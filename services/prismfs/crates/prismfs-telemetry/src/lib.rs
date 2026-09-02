//! Shared logging, audit, and metrics vocabulary for PrismFS.

use prismfs_core::{RequestContext, VirtualPath};
use serde::Serialize;
use tracing_subscriber::{EnvFilter, layer::SubscriberExt, util::SubscriberInitExt};

/// Stable metric names consumed by dashboards and alerts.
pub mod metrics {
    /// Filesystem requests by operation and result.
    pub const REQUESTS_TOTAL: &str = "prismfs_requests_total";
    /// Bytes returned to filesystem clients.
    pub const BYTES_READ_TOTAL: &str = "prismfs_bytes_read_total";
    /// Cache hits.
    pub const CACHE_HITS_TOTAL: &str = "prismfs_cache_hits_total";
    /// Cache misses.
    pub const CACHE_MISSES_TOTAL: &str = "prismfs_cache_misses_total";
    /// Policy rejections.
    pub const ACCESS_DENIED_TOTAL: &str = "prismfs_access_denied_total";
    /// Active file handles.
    pub const ACTIVE_FILE_HANDLES: &str = "prismfs_active_file_handles";
}

/// Log rendering mode.
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum LogFormat {
    /// Human-readable local output.
    Pretty,
    /// Structured JSON for collection.
    Json,
}

/// Process-wide tracing configuration.
#[derive(Clone, Debug)]
pub struct TelemetryConfig {
    /// Default filter used when `RUST_LOG` is absent.
    pub default_filter: String,
    /// Log rendering mode.
    pub format: LogFormat,
}

impl Default for TelemetryConfig {
    fn default() -> Self {
        Self {
            default_filter: "prismfs=info".to_owned(),
            format: LogFormat::Pretty,
        }
    }
}

/// Installs the global tracing subscriber.
pub fn init(config: &TelemetryConfig) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let filter = EnvFilter::try_from_default_env()
        .unwrap_or_else(|_| EnvFilter::new(&config.default_filter));
    let registry = tracing_subscriber::registry().with(filter);

    match config.format {
        LogFormat::Pretty => registry.with(tracing_subscriber::fmt::layer()).try_init()?,
        LogFormat::Json => registry
            .with(tracing_subscriber::fmt::layer().json())
            .try_init()?,
    }
    Ok(())
}

/// Structured, high-cardinality event intended for logs rather than metrics labels.
#[derive(Clone, Debug, Serialize)]
pub struct AuditEvent<'a> {
    /// Request identity and tenant/principal context.
    pub context: &'a RequestContext,
    /// Operation name.
    pub operation: &'a str,
    /// Virtual path being accessed.
    pub path: &'a VirtualPath,
    /// Policy or operation result.
    pub result: &'a str,
    /// Operation duration in milliseconds.
    pub duration_ms: f64,
}

/// Emits a structured audit event with bounded metric labels.
pub fn record(event: &AuditEvent<'_>) {
    ::metrics::counter!(
        metrics::REQUESTS_TOTAL,
        "operation" => event.operation.to_owned(),
        "result" => event.result.to_owned()
    )
    .increment(1);

    tracing::info!(
        target: "prismfs::audit",
        request_id = %event.context.request_id,
        tenant_id = %event.context.tenant_id,
        principal_id = %event.context.principal_id,
        operation = event.operation,
        virtual_path = %event.path,
        result = event.result,
        duration_ms = event.duration_ms,
        "filesystem access"
    );
}

/// Records a cache result without placing object identities in metric labels.
pub fn record_cache(hit: bool, bytes: usize) {
    let metric = if hit {
        metrics::CACHE_HITS_TOTAL
    } else {
        metrics::CACHE_MISSES_TOTAL
    };
    ::metrics::counter!(metric).increment(1);
    ::metrics::counter!(metrics::BYTES_READ_TOTAL).increment(bytes as u64);
    tracing::debug!(cache_hit = hit, bytes, "object range cache result");
}

/// Adjusts the number of live FUSE file handles.
pub fn record_file_handle(delta: f64) {
    ::metrics::gauge!(metrics::ACTIVE_FILE_HANDLES).increment(delta);
}
