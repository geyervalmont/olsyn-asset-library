//! Shared logging, audit, and metrics vocabulary for PrismFS.

use std::{
    net::SocketAddr,
    time::{SystemTime, UNIX_EPOCH},
};

use metrics_exporter_prometheus::PrometheusBuilder;
use prismfs_core::{RequestContext, VirtualPath};
use serde::Serialize;
use tracing_subscriber::{EnvFilter, layer::SubscriberExt, util::SubscriberInitExt};

pub mod audit;

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

/// Exposes bounded-cardinality metrics to a private Prometheus scrape endpoint.
/// The endpoint is telemetry only; readiness must exercise the filesystem/SMB.
pub fn install_metrics(
    address: SocketAddr,
) -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    PrometheusBuilder::new()
        .with_http_listener(address)
        .set_buckets(&[0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1.0, 5.0, 30.0])?
        .install()?;
    ::metrics::gauge!("prismfs_process_start_time_seconds").set(unix_seconds());
    ::metrics::gauge!("prismfs_manifest_last_success_timestamp_seconds").set(0.0);
    ::metrics::gauge!("prismfs_manifest_remote").set(0.0);
    Ok(())
}

fn unix_seconds() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs_f64()
}

/// Reports only successfully validated namespace loads (including unchanged polls).
pub fn record_manifest(success: bool, files: Option<usize>) {
    ::metrics::counter!("prismfs_manifest_refresh_total", "result" => if success { "success" } else { "error" }).increment(1);
    if success {
        ::metrics::gauge!("prismfs_manifest_last_success_timestamp_seconds").set(unix_seconds());
        if let Some(files) = files {
            ::metrics::gauge!("prismfs_manifest_files").set(files as f64);
        }
    }
}

/// Whether freshness monitoring should expect remote manifest polling.
pub fn record_remote_manifest() {
    ::metrics::gauge!("prismfs_manifest_remote").set(1.0);
}

/// Audit delivery state does not expose paths, users, or bearer credentials.
pub fn record_audit_queue(queued: usize, dropped: u64) {
    ::metrics::gauge!("prismfs_audit_queue_events").set(queued as f64);
    ::metrics::gauge!("prismfs_audit_dropped_events").set(dropped as f64);
}

/// Records success/failure of a batch, independent of queue length.
pub fn record_audit_delivery(success: bool) {
    ::metrics::counter!("prismfs_audit_batches_total", "result" => if success { "success" } else { "error" }).increment(1);
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
    /// Bytes returned to the client, for reads.
    pub bytes: Option<u64>,
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

    ::metrics::histogram!("prismfs_request_duration_seconds", "operation" => event.operation.to_owned())
        .record(event.duration_ms / 1_000.0);
    if event.result == "deny" {
        ::metrics::counter!(metrics::ACCESS_DENIED_TOTAL).increment(1);
    }
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

    if let Some(queue) = audit::installed()
        && queue.accepts(event.operation)
    {
        queue.push(audit::AccessEvent::now(event));
    }
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn read_failures_and_manifest_freshness_exclude_private_labels() {
        let recorder = PrometheusBuilder::new().build_recorder();
        let handle = recorder.handle();
        ::metrics::with_local_recorder(&recorder, || {
            record_manifest(true, Some(42));
            record_remote_manifest();
            let before = handle.render();
            let freshness = before
                .lines()
                .find(|line| line.starts_with("prismfs_manifest_last_success_timestamp_seconds "))
                .expect("freshness metric")
                .to_owned();
            record_manifest(false, None);
            record(&AuditEvent {
                context: &RequestContext::new("private-tenant", "private-principal"),
                operation: "read",
                path: &VirtualPath::parse("/private-file.png").expect("path"),
                result: "error",
                bytes: None,
                duration_ms: 250.0,
            });
            record_audit_queue(12, 3);
            record_audit_delivery(false);
            let output = handle.render();
            assert!(output.contains(&freshness));
            assert!(output.contains("prismfs_manifest_files 42"));
            assert!(output.contains("prismfs_manifest_refresh_total{result=\"error\"} 1"));
            assert!(
                output.contains("prismfs_requests_total{operation=\"read\",result=\"error\"} 1")
            );
            assert!(
                output.contains("prismfs_request_duration_seconds_sum{operation=\"read\"} 0.25")
            );
            assert!(output.contains("prismfs_audit_queue_events 12"));
            assert!(output.contains("prismfs_audit_dropped_events 3"));
            assert!(!output.contains("private-"));
        });
    }
}
