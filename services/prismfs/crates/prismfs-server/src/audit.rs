//! Ships queued access events to the control plane.

use std::{sync::Arc, thread, time::Duration};

use anyhow::{Context, Result, bail};
use prismfs_telemetry::audit::{AccessBatch, AuditQueue};
use reqwest::{Client, header};
use tracing::{debug, info, warn};

/// Suffix of a manifest URL that the audit URL replaces.
const MANIFEST_SUFFIX: &str = "/manifest.yaml";
/// Path the control plane accepts access batches on, relative to the drive.
const ACCESSES_SUFFIX: &str = "/accesses";

/// The audit endpoint implied by a manifest URL:
/// `…/drives/{slug}/manifest.yaml` becomes `…/drives/{slug}/accesses`.
pub fn derive_url(manifest_url: &str) -> Result<String> {
    let trimmed = manifest_url.trim_end_matches('/');
    match trimmed.strip_suffix(MANIFEST_SUFFIX) {
        Some(base) if !base.is_empty() => Ok(format!("{base}{ACCESSES_SUFFIX}")),
        _ => bail!(
            "cannot derive an audit URL from {manifest_url}; pass --audit-url or use a manifest URL ending in {MANIFEST_SUFFIX}"
        ),
    }
}

/// Posts batches of access events with the drive token.
pub struct AuditShipper {
    client: Client,
    url: String,
    token: String,
    queue: Arc<AuditQueue>,
    batch: usize,
    interval: Duration,
}

impl AuditShipper {
    /// Creates a shipper for one drive.
    pub fn new(
        url: impl Into<String>,
        token: impl Into<String>,
        queue: Arc<AuditQueue>,
        batch: usize,
        interval: Duration,
    ) -> Result<Self> {
        let client = Client::builder()
            .timeout(Duration::from_secs(30))
            .user_agent(concat!("prismfs/", env!("CARGO_PKG_VERSION")))
            .build()
            .context("failed to build the audit HTTP client")?;
        Ok(Self {
            client,
            url: url.into(),
            token: token.into(),
            queue,
            batch: batch.max(1),
            interval: interval.max(Duration::from_secs(1)),
        })
    }

    /// The endpoint batches go to.
    #[must_use]
    pub fn url(&self) -> &str {
        &self.url
    }

    /// Posts one batch. On failure the batch goes back to the queue for the
    /// next flush. Returns how many events were delivered.
    pub async fn flush_once(&self) -> usize {
        let events = self.queue.drain(self.batch);
        if events.is_empty() {
            return 0;
        }
        let count = events.len();
        let batch = AccessBatch { events };
        match self.post(&batch).await {
            Ok(()) => {
                debug!(count, "access events shipped");
                count
            }
            Err(error) => {
                self.queue.requeue(batch.events);
                warn!(%error, count, queued = self.queue.len(), "access events kept for retry");
                0
            }
        }
    }

    async fn post(&self, batch: &AccessBatch) -> Result<()> {
        let body = serde_json::to_vec(batch).context("failed to encode the access batch")?;
        let response = self
            .client
            .post(&self.url)
            .bearer_auth(&self.token)
            .header(header::CONTENT_TYPE, "application/json")
            .header(header::ACCEPT, "application/json")
            .body(body)
            .send()
            .await
            .with_context(|| format!("access batch to {} failed", self.url))?;
        let status = response.status();
        if status.is_success() {
            Ok(())
        } else {
            bail!("access batch to {} returned {status}", self.url)
        }
    }

    /// Runs the flush loop on its own thread until the process exits.
    pub fn spawn(self) {
        info!(
            url = self.url(),
            interval_secs = self.interval.as_secs(),
            batch = self.batch,
            "access events will be shipped to the control plane"
        );
        thread::Builder::new()
            .name("prismfs-audit-ship".into())
            .spawn(move || {
                let runtime = match tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                {
                    Ok(runtime) => runtime,
                    Err(error) => {
                        warn!(%error, "audit shipping disabled: no runtime");
                        return;
                    }
                };
                runtime.block_on(async {
                    loop {
                        tokio::time::sleep(self.interval).await;
                        // Drain everything that is waiting, one batch at a time,
                        // but stop at the first failure so the backlog is retried
                        // on the next tick rather than hammered now.
                        while self.flush_once().await > 0 {}
                    }
                });
            })
            .map(|_| ())
            .unwrap_or_else(|error| warn!(%error, "audit shipping disabled: thread not started"));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn audit_url_follows_the_manifest_url() {
        assert_eq!(
            derive_url("https://opal.example/prismfs/drives/studio-share/manifest.yaml")
                .expect("derives"),
            "https://opal.example/prismfs/drives/studio-share/accesses"
        );
        assert_eq!(
            derive_url("http://asset-library-web/prismfs/drives/x/manifest.yaml/")
                .expect("derives"),
            "http://asset-library-web/prismfs/drives/x/accesses"
        );
        assert!(derive_url("https://opal.example/manifest.json").is_err());
        assert!(derive_url("/manifest.yaml").is_err());
    }
}
