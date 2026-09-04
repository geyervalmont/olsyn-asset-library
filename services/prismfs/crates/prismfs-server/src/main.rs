use std::{
    collections::BTreeSet,
    env,
    fs::{self, File},
    io::BufReader,
    path::{Path, PathBuf},
    sync::Arc,
    thread,
    time::Duration,
};

use anyhow::{Context, Result, bail};
use clap::{Parser, Subcommand, ValueEnum};
use prismfs_cache::{MemoryCache, ObjectCache};
use prismfs_core::{
    Namespace, NamespaceManifest, StaticNamespace, SwappableNamespace, VirtualPath,
};
use prismfs_fuse::{FuseAdapter, FuseConfig};
use prismfs_policy::{AccessPolicy, DenyPrefixes};
use prismfs_smb::SambaConfig;
use prismfs_storage::{ObjectReader, ObjectStoreReader};
use prismfs_telemetry::{LogFormat, TelemetryConfig, audit::AuditQueue};
use tracing::{info, warn};

mod audit;
mod remote;

use audit::AuditShipper;
use remote::RemoteManifest;

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
        /// Fetch the manifest from the control plane instead of a file.
        #[arg(long, env = "PRISMFS_MANIFEST_URL")]
        manifest_url: Option<String>,
        /// Drive token presented to the control plane.
        #[arg(long, env = "PRISMFS_MANIFEST_TOKEN", hide_env_values = true)]
        manifest_token: Option<String>,
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
        /// Fetch the manifest from the control plane and keep it refreshed.
        #[arg(long, env = "PRISMFS_MANIFEST_URL")]
        manifest_url: Option<String>,
        /// Drive token presented to the control plane.
        #[arg(long, env = "PRISMFS_MANIFEST_TOKEN", hide_env_values = true)]
        manifest_token: Option<String>,
        /// Seconds between manifest refreshes when a URL is used.
        #[arg(long, env = "PRISMFS_REFRESH_INTERVAL", default_value_t = 30)]
        refresh_interval: u64,
        /// Tenant attached to filesystem policy and audit events.
        #[arg(long, env = "PRISMFS_TENANT_ID", default_value = "local")]
        tenant_id: String,
        /// Hide and deny this absolute path and every descendant. Repeatable.
        #[arg(long, env = "PRISMFS_DENY_PREFIX", value_delimiter = ',')]
        deny_prefix: Vec<VirtualPath>,
        /// Where access events are posted. Defaults to the manifest URL with
        /// `/manifest.yaml` replaced by `/accesses`; off without a manifest URL.
        #[arg(long, env = "PRISMFS_AUDIT_URL")]
        audit_url: Option<String>,
        /// Seconds between access event flushes.
        #[arg(long, env = "PRISMFS_AUDIT_FLUSH_INTERVAL", default_value_t = 5)]
        audit_flush_interval: u64,
        /// Most events posted per request.
        #[arg(long, env = "PRISMFS_AUDIT_BATCH", default_value_t = 500)]
        audit_batch: usize,
        /// Most events kept while the control plane is unreachable; the
        /// oldest are dropped beyond this.
        #[arg(long, env = "PRISMFS_AUDIT_QUEUE", default_value_t = 10_000)]
        audit_queue: usize,
        /// Operations shipped; everything else stays in the local log.
        #[arg(long, env = "PRISMFS_AUDIT_OPERATIONS", value_delimiter = ',', default_values_t = default_audit_operations())]
        audit_operations: Vec<String>,
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
        /// Only this Samba user may connect (set its password with smbpasswd);
        /// without it the share is guest-only.
        #[arg(long)]
        user: Option<String>,
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
        Command::Doctor {
            manifest,
            manifest_url,
            manifest_token,
            check_s3,
        } => doctor(
            ManifestSource::new(manifest, manifest_url, manifest_token)?,
            check_s3,
        ),
        Command::Mount {
            mountpoint,
            manifest,
            manifest_url,
            manifest_token,
            refresh_interval,
            tenant_id,
            deny_prefix,
            audit_url,
            audit_flush_interval,
            audit_batch,
            audit_queue,
            audit_operations,
        } => {
            let audit = AuditSettings::new(
                audit_url,
                manifest_url.as_deref(),
                manifest_token.as_deref(),
                Duration::from_secs(audit_flush_interval.max(1)),
                audit_batch,
                audit_queue,
                audit_operations,
            )?;
            mount(
                mountpoint,
                ManifestSource::new(manifest, manifest_url, manifest_token)?,
                Duration::from_secs(refresh_interval.max(1)),
                tenant_id,
                deny_prefix,
                audit,
            )
        }
        Command::SambaConfig {
            mountpoint,
            share_name,
            guest_account,
            user,
        } => samba_config(mountpoint, share_name, guest_account, user),
    }
}

fn default_audit_operations() -> Vec<String> {
    prismfs_telemetry::audit::DEFAULT_OPERATIONS
        .iter()
        .map(|operation| (*operation).to_owned())
        .collect()
}

/// How access events reach the control plane, if at all.
struct AuditSettings {
    url: Option<String>,
    token: Option<String>,
    interval: Duration,
    batch: usize,
    capacity: usize,
    operations: Vec<String>,
}

impl AuditSettings {
    fn new(
        url: Option<String>,
        manifest_url: Option<&str>,
        manifest_token: Option<&str>,
        interval: Duration,
        batch: usize,
        capacity: usize,
        operations: Vec<String>,
    ) -> Result<Self> {
        let url = match (url, manifest_url) {
            (Some(url), _) => Some(url),
            (None, Some(manifest_url)) => Some(audit::derive_url(manifest_url)?),
            (None, None) => None,
        };
        if url.is_some() && manifest_token.is_none() {
            bail!("--manifest-token is required to ship access events");
        }
        Ok(Self {
            url,
            token: manifest_token.map(str::to_owned),
            interval,
            batch,
            capacity,
            operations,
        })
    }

    /// Installs the queue and starts the shipper when a URL is configured.
    fn start(self) -> Result<()> {
        let (Some(url), Some(token)) = (self.url, self.token) else {
            info!("access events stay in the local log: no control plane URL");
            return Ok(());
        };
        let queue = Arc::new(AuditQueue::new(self.capacity, self.operations));
        prismfs_telemetry::audit::install(Arc::clone(&queue))
            .map_err(|_| anyhow::anyhow!("the audit queue was already installed"))?;
        AuditShipper::new(url, token, queue, self.batch, self.interval)?.spawn();
        Ok(())
    }
}

/// Where the namespace comes from: a file on disk, or the control plane.
enum ManifestSource {
    File(PathBuf),
    Remote(RemoteManifest),
}

impl ManifestSource {
    fn new(path: PathBuf, url: Option<String>, token: Option<String>) -> Result<Self> {
        match url {
            Some(url) => {
                let token = token.context("--manifest-token is required with --manifest-url")?;
                Ok(Self::Remote(RemoteManifest::new(url, token)?))
            }
            None => Ok(Self::File(path)),
        }
    }

    fn describe(&self) -> String {
        match self {
            Self::File(path) => path.display().to_string(),
            Self::Remote(remote) => remote.url().to_owned(),
        }
    }

    fn load(&mut self) -> Result<NamespaceManifest> {
        match self {
            Self::File(path) => load_manifest(path),
            Self::Remote(remote) => remote.fetch_initial(),
        }
    }
}

/// Keeps a mounted namespace in step with the control plane.
fn spawn_refresher(
    mut remote: RemoteManifest,
    interval: Duration,
    bucket: String,
    target: Arc<SwappableNamespace>,
) {
    thread::Builder::new()
        .name("prismfs-manifest-refresh".into())
        .spawn(move || {
            let runtime = match tokio::runtime::Builder::new_current_thread()
                .enable_all()
                .build()
            {
                Ok(runtime) => runtime,
                Err(error) => {
                    warn!(%error, "manifest refresh disabled: no runtime");
                    return;
                }
            };

            runtime.block_on(async {
                loop {
                    tokio::time::sleep(interval).await;
                    match remote.fetch().await {
                        Ok(None) => {}
                        Ok(Some(manifest)) => match refresh(&manifest, &bucket) {
                            Ok(namespace) => match target.replace(namespace) {
                                Ok(()) => {
                                    info!(files = manifest.files.len(), "namespace refreshed")
                                }
                                Err(error) => warn!(%error, "namespace swap failed"),
                            },
                            Err(error) => warn!(%error, "refreshed manifest rejected"),
                        },
                        Err(error) => warn!(%error, "manifest refresh failed"),
                    }
                }
            });
        })
        .map(|_| ())
        .unwrap_or_else(|error| warn!(%error, "manifest refresh disabled: thread not started"));
}

fn refresh(manifest: &NamespaceManifest, bucket: &str) -> Result<Arc<dyn Namespace>> {
    if let Some(refreshed_bucket) = manifest_bucket(manifest)?
        && refreshed_bucket != bucket
    {
        bail!("refreshed manifest moved from bucket {bucket} to {refreshed_bucket}");
    }
    namespace(manifest)
}

fn load_manifest(path: &Path) -> Result<NamespaceManifest> {
    let file = File::open(path)
        .with_context(|| format!("failed to open namespace manifest {}", path.display()))?;
    let manifest = serde_yaml::from_reader(BufReader::new(file))
        .with_context(|| format!("failed to parse namespace manifest {}", path.display()))?;
    Ok(manifest)
}

/// The single bucket a manifest refers to, or `None` for an empty manifest.
fn manifest_bucket(manifest: &NamespaceManifest) -> Result<Option<String>> {
    let buckets = manifest
        .files
        .iter()
        .map(|file| file.object.bucket.as_str())
        .collect::<BTreeSet<_>>();
    match buckets.len() {
        0 => Ok(None),
        1 => Ok(Some(
            (*buckets.first().expect("one bucket exists")).to_owned(),
        )),
        _ => bail!(
            "a PrismFS process currently supports one bucket; manifest contains: {}",
            buckets.into_iter().collect::<Vec<_>>().join(", ")
        ),
    }
}

/// The bucket to serve: the manifest's, or the configured one when the
/// manifest is empty (a drive with nothing published yet).
fn serving_bucket(manifest: &NamespaceManifest) -> Result<String> {
    match manifest_bucket(manifest)? {
        Some(bucket) => Ok(bucket),
        None => env::var("PRISMFS_S3_BUCKET")
            .ok()
            .filter(|bucket| !bucket.is_empty())
            .context("the manifest is empty and PRISMFS_S3_BUCKET is not set"),
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

fn doctor(mut source: ManifestSource, check_s3: bool) -> Result<()> {
    let manifest = source.load()?;
    let bucket = serving_bucket(&manifest)?;
    namespace(&manifest)?;
    if check_s3 {
        s3_reader(&bucket)?;
    }

    println!("PrismFS composition: ok");
    println!("mode: read-only");
    println!("manifest: {}", source.describe());
    println!("files: {}", manifest.files.len());
    println!("bucket: {bucket}");
    println!("s3 client: {}", if check_s3 { "ok" } else { "not checked" });
    Ok(())
}

fn mount(
    mountpoint: PathBuf,
    mut source: ManifestSource,
    refresh_interval: Duration,
    tenant_id: String,
    deny_prefix: Vec<VirtualPath>,
    audit: AuditSettings,
) -> Result<()> {
    let manifest = source.load()?;
    let bucket = serving_bucket(&manifest)?;
    let swappable = Arc::new(SwappableNamespace::new(namespace(&manifest)?));
    audit.start()?;
    if let ManifestSource::Remote(remote) = source {
        info!(
            url = remote.url(),
            interval_secs = refresh_interval.as_secs(),
            files = manifest.files.len(),
            "namespace loaded from the control plane"
        );
        spawn_refresher(
            remote,
            refresh_interval,
            bucket.clone(),
            Arc::clone(&swappable),
        );
    }
    let namespace: Arc<dyn Namespace> = swappable;
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

fn samba_config(
    mountpoint: PathBuf,
    share_name: String,
    guest_account: String,
    user: Option<String>,
) -> Result<()> {
    let config = match user {
        Some(user) => SambaConfig::read_only_user(share_name, mountpoint, guest_account, user),
        None => SambaConfig::read_only_guest(share_name, mountpoint, guest_account),
    };
    let rendered = config
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
            manifest_bucket(&manifest(&["assets", "assets"]))
                .expect("one bucket")
                .as_deref(),
            Some("assets")
        );
        assert!(manifest_bucket(&manifest(&["assets", "other"])).is_err());
        assert_eq!(
            manifest_bucket(&manifest(&[])).expect("empty is fine"),
            None
        );
    }
}
