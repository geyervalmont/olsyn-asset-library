use std::{
    collections::BTreeSet,
    env,
    fs::{self, File},
    io::BufReader,
    path::{Path, PathBuf},
    sync::Arc,
};

use anyhow::{Context, Result, bail};
use clap::{Parser, Subcommand, ValueEnum};
use prismfs_cache::{MemoryCache, ObjectCache};
use prismfs_core::{Namespace, NamespaceManifest, StaticNamespace, VirtualPath};
use prismfs_fuse::{FuseAdapter, FuseConfig};
use prismfs_policy::{AccessPolicy, DenyPrefixes};
use prismfs_smb::SambaConfig;
use prismfs_storage::{ObjectReader, ObjectStoreReader};
use prismfs_telemetry::{LogFormat, TelemetryConfig};

#[derive(Debug, Parser)]
#[command(name = "prismfs", version, about = "PrismFS data-plane service")]
struct Cli {
    #[arg(long, value_enum, default_value_t = OutputFormat::Pretty)]
    log_format: OutputFormat,

    #[command(subcommand)]
    command: Command,
}

#[derive(Clone, Copy, Debug, ValueEnum)]
enum OutputFormat {
    Pretty,
    Json,
}

impl From<OutputFormat> for LogFormat {
    fn from(value: OutputFormat) -> Self {
        match value {
            OutputFormat::Pretty => Self::Pretty,
            OutputFormat::Json => Self::Json,
        }
    }
}

#[derive(Debug, Subcommand)]
enum Command {
    /// Validate the namespace manifest and local composition.
    Doctor {
        /// Namespace manifest to validate.
        #[arg(long, env = "PRISMFS_MANIFEST", default_value = "dev/namespace.yaml")]
        manifest: PathBuf,
        /// Also validate that the current S3 environment can build a client.
        #[arg(long)]
        check_s3: bool,
    },
    /// Mount an S3-backed namespace as a read-only FUSE filesystem.
    Mount {
        /// Directory to create and use as the mountpoint.
        #[arg(long, env = "PRISMFS_MOUNTPOINT", default_value = "mnt")]
        mountpoint: PathBuf,
        /// Namespace manifest to project.
        #[arg(long, env = "PRISMFS_MANIFEST", default_value = "dev/namespace.yaml")]
        manifest: PathBuf,
        /// Tenant attached to filesystem policy and audit events.
        #[arg(long, env = "PRISMFS_TENANT_ID", default_value = "local")]
        tenant_id: String,
        /// Hide and deny this absolute path and every descendant. Repeatable.
        #[arg(long, env = "PRISMFS_DENY_PREFIX", value_delimiter = ',')]
        deny_prefix: Vec<VirtualPath>,
    },
    /// Render a validated read-only Samba configuration for a PrismFS mount.
    SambaConfig {
        /// Absolute path to the mounted PrismFS namespace.
        #[arg(long, env = "PRISMFS_MOUNTPOINT")]
        mountpoint: PathBuf,
        /// SMB share name.
        #[arg(long, env = "PRISMFS_SMB_SHARE", default_value = "prismfs")]
        share_name: String,
        /// Unix account Samba uses for guest requests.
        #[arg(long, env = "PRISMFS_SMB_GUEST", default_value = "root")]
        guest_account: String,
    },
}

fn main() -> Result<()> {
    let cli = Cli::parse();
    prismfs_telemetry::init(&TelemetryConfig {
        format: cli.log_format.into(),
        ..TelemetryConfig::default()
    })
    .map_err(|error| anyhow::anyhow!(error.to_string()))?;

    match cli.command {
        Command::Doctor { manifest, check_s3 } => doctor(&manifest, check_s3),
        Command::Mount {
            mountpoint,
            manifest,
            tenant_id,
            deny_prefix,
        } => mount(mountpoint, &manifest, tenant_id, deny_prefix),
        Command::SambaConfig {
            mountpoint,
            share_name,
            guest_account,
        } => samba_config(mountpoint, share_name, guest_account),
    }
}

fn load_manifest(path: &Path) -> Result<NamespaceManifest> {
    let file = File::open(path)
        .with_context(|| format!("failed to open namespace manifest {}", path.display()))?;
    let manifest = serde_yaml::from_reader(BufReader::new(file))
        .with_context(|| format!("failed to parse namespace manifest {}", path.display()))?;
    Ok(manifest)
}

fn manifest_bucket(manifest: &NamespaceManifest) -> Result<String> {
    let buckets = manifest
        .files
        .iter()
        .map(|file| file.object.bucket.as_str())
        .collect::<BTreeSet<_>>();
    match buckets.len() {
        0 => bail!("namespace manifest must contain at least one file"),
        1 => Ok((*buckets.first().expect("one bucket exists")).to_owned()),
        _ => bail!(
            "a PrismFS process currently supports one bucket; manifest contains: {}",
            buckets.into_iter().collect::<Vec<_>>().join(", ")
        ),
    }
}

fn namespace(manifest: &NamespaceManifest) -> Result<Arc<dyn Namespace>> {
    let namespace = StaticNamespace::from_manifest(manifest)
        .context("namespace manifest violates a PrismFS invariant")?;
    Ok(Arc::new(namespace))
}

fn s3_reader(bucket: &str) -> Result<Arc<dyn ObjectReader>> {
    let configured_bucket = env::var("PRISMFS_S3_BUCKET").unwrap_or_else(|_| bucket.to_owned());
    if configured_bucket != bucket {
        bail!("PRISMFS_S3_BUCKET is {configured_bucket}, but the manifest uses bucket {bucket}");
    }
    let reader = ObjectStoreReader::s3_from_env(configured_bucket)
        .context("failed to configure the S3-compatible object reader")?;
    Ok(Arc::new(reader))
}

fn doctor(manifest_path: &Path, check_s3: bool) -> Result<()> {
    let manifest = load_manifest(manifest_path)?;
    let bucket = manifest_bucket(&manifest)?;
    namespace(&manifest)?;
    if check_s3 {
        s3_reader(&bucket)?;
    }

    println!("PrismFS composition: ok");
    println!("mode: read-only");
    println!("manifest: {}", manifest_path.display());
    println!("files: {}", manifest.files.len());
    println!("bucket: {bucket}");
    println!("s3 client: {}", if check_s3 { "ok" } else { "not checked" });
    Ok(())
}

fn mount(
    mountpoint: PathBuf,
    manifest_path: &Path,
    tenant_id: String,
    deny_prefix: Vec<VirtualPath>,
) -> Result<()> {
    let manifest = load_manifest(manifest_path)?;
    let bucket = manifest_bucket(&manifest)?;
    let namespace = namespace(&manifest)?;
    let storage = s3_reader(&bucket)?;
    let policy: Arc<dyn AccessPolicy> = Arc::new(DenyPrefixes::new(deny_prefix));
    let cache: Arc<dyn ObjectCache> = Arc::new(MemoryCache::default());
    fs::create_dir_all(&mountpoint)
        .with_context(|| format!("failed to create mountpoint {}", mountpoint.display()))?;

    let mut config = FuseConfig::read_only(&mountpoint);
    config.tenant_id = tenant_id;
    FuseAdapter::new(config, namespace, storage, policy, cache)
        .mount()
        .context("PrismFS FUSE adapter failed")
}

fn samba_config(mountpoint: PathBuf, share_name: String, guest_account: String) -> Result<()> {
    let rendered = SambaConfig::read_only_guest(share_name, mountpoint, guest_account)
        .context("invalid Samba configuration")?
        .render()
        .context("failed to render Samba configuration")?;
    print!("{rendered}");
    Ok(())
}

#[cfg(test)]
mod tests {
    use prismfs_core::{ManifestFile, ObjectRef};

    use super::*;

    fn manifest(buckets: &[&str]) -> NamespaceManifest {
        NamespaceManifest {
            version: 1,
            files: buckets
                .iter()
                .enumerate()
                .map(|(index, bucket)| ManifestFile {
                    path: VirtualPath::parse(format!("/file-{index}")).expect("valid path"),
                    object: ObjectRef {
                        bucket: (*bucket).to_owned(),
                        key: format!("file-{index}"),
                        size: 1,
                        version: None,
                    },
                })
                .collect(),
        }
    }

    #[test]
    fn manifests_are_scoped_to_one_bucket() {
        assert_eq!(
            manifest_bucket(&manifest(&["assets", "assets"])).expect("one bucket"),
            "assets"
        );
        assert!(manifest_bucket(&manifest(&["assets", "other"])).is_err());
        assert!(manifest_bucket(&manifest(&[])).is_err());
    }
}
