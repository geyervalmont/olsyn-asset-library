use std::{path::PathBuf, sync::Arc};

use anyhow::{Context, Result};
use clap::{Parser, Subcommand, ValueEnum};
use prismfs_cache::{MemoryCache, ObjectCache};
use prismfs_core::{Namespace, StaticNamespace};
use prismfs_fuse::{FuseAdapter, FuseConfig};
use prismfs_policy::{AccessPolicy, AllowAll};
use prismfs_storage::{MemoryObjectReader, ObjectReader};
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
    /// Validate the process composition without mounting FUSE.
    Doctor,
    /// Validate a mountpoint and start the FUSE adapter.
    Mount {
        /// Existing directory to use as the mountpoint.
        #[arg(long, env = "PRISMFS_MOUNTPOINT", default_value = "mnt")]
        mountpoint: PathBuf,
    },
}

#[tokio::main]
async fn main() -> Result<()> {
    let cli = Cli::parse();
    prismfs_telemetry::init(&TelemetryConfig {
        format: cli.log_format.into(),
        ..TelemetryConfig::default()
    })
    .map_err(|error| anyhow::anyhow!(error.to_string()))?;

    match cli.command {
        Command::Doctor => doctor(),
        Command::Mount { mountpoint } => mount(mountpoint),
    }
}

fn components(mountpoint: PathBuf) -> FuseAdapter {
    let namespace: Arc<dyn Namespace> = Arc::new(StaticNamespace::new());
    let storage: Arc<dyn ObjectReader> = Arc::new(MemoryObjectReader::default());
    let policy: Arc<dyn AccessPolicy> = Arc::new(AllowAll);
    let cache: Arc<dyn ObjectCache> = Arc::new(MemoryCache::default());

    FuseAdapter::new(
        FuseConfig::read_only(mountpoint),
        namespace,
        storage,
        policy,
        cache,
    )
}

fn doctor() -> Result<()> {
    let adapter = components(PathBuf::from("mnt"));
    adapter
        .validate()
        .context("PrismFS adapter validation failed")?;
    println!("PrismFS composition: ok");
    println!("mode: read-only");
    println!("mountpoint: {}", adapter.config().mountpoint.display());
    Ok(())
}

fn mount(mountpoint: PathBuf) -> Result<()> {
    components(mountpoint)
        .mount()
        .context("PrismFS FUSE adapter failed")
}
