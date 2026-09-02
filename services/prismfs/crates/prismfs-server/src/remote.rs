//! Fetches namespace manifests from the control plane.

use std::time::Duration;

use anyhow::{Context, Result, bail};
use prismfs_core::NamespaceManifest;
use reqwest::{Client, StatusCode, header};

/// A manifest served over HTTP, authenticated with a drive token and
/// refreshed cheaply through `ETag` / `If-None-Match`.
pub struct RemoteManifest {
    client: Client,
    url: String,
    token: String,
    etag: Option<String>,
}

impl RemoteManifest {
    /// Creates a client for one manifest URL.
    pub fn new(url: impl Into<String>, token: impl Into<String>) -> Result<Self> {
        let client = Client::builder()
            .timeout(Duration::from_secs(30))
            .user_agent(concat!("prismfs/", env!("CARGO_PKG_VERSION")))
            .build()
            .context("failed to build the manifest HTTP client")?;
        Ok(Self {
            client,
            url: url.into(),
            token: token.into(),
            etag: None,
        })
    }

    /// The manifest URL, for logs and diagnostics.
    #[must_use]
    pub fn url(&self) -> &str {
        &self.url
    }

    /// Fetches the manifest. Returns `None` when it has not changed since the
    /// previous successful fetch.
    pub async fn fetch(&mut self) -> Result<Option<NamespaceManifest>> {
        let mut request = self.client.get(&self.url).bearer_auth(&self.token);
        if let Some(etag) = &self.etag {
            request = request.header(header::IF_NONE_MATCH, etag);
        }

        let response = request
            .send()
            .await
            .with_context(|| format!("manifest request to {} failed", self.url))?;

        match response.status() {
            StatusCode::NOT_MODIFIED => Ok(None),
            StatusCode::OK => {
                let etag = response
                    .headers()
                    .get(header::ETAG)
                    .and_then(|value| value.to_str().ok())
                    .map(str::to_owned);
                let body = response
                    .text()
                    .await
                    .context("failed to read the manifest body")?;
                let manifest: NamespaceManifest = serde_yaml::from_str(&body)
                    .with_context(|| format!("failed to parse the manifest from {}", self.url))?;
                self.etag = etag;
                Ok(Some(manifest))
            }
            StatusCode::UNAUTHORIZED | StatusCode::FORBIDDEN => {
                bail!("the manifest token was rejected by {}", self.url)
            }
            status => bail!("manifest request to {} returned {status}", self.url),
        }
    }

    /// Performs the first fetch on a private runtime, before any mount exists.
    pub fn fetch_initial(&mut self) -> Result<NamespaceManifest> {
        let runtime = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .context("failed to create the manifest runtime")?;
        runtime
            .block_on(self.fetch())?
            .context("the control plane answered 304 to a first fetch")
    }
}
