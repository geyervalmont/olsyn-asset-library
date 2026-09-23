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
            StatusCode::NOT_MODIFIED if self.etag.is_some() => Ok(None),
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

    /// Do not acknowledge a parsed manifest that failed semantic validation.
    /// Otherwise the next 304 could incorrectly report a rejected snapshot as healthy.
    pub fn reject(&mut self) {
        self.etag = None;
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

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        io::{Read, Write},
        net::TcpListener,
        thread,
    };

    #[test]
    fn rejecting_a_snapshot_forces_a_full_fetch() {
        let listener = TcpListener::bind("127.0.0.1:0").expect("listener");
        let url = format!(
            "http://{}/manifest.yaml",
            listener.local_addr().expect("address")
        );
        let server = thread::spawn(move || {
            for index in 0..3 {
                let (mut stream, _) = listener.accept().expect("request");
                stream
                    .set_read_timeout(Some(Duration::from_secs(5)))
                    .expect("timeout");
                let mut request = Vec::new();
                let mut byte = [0];
                while !request.ends_with(b"\r\n\r\n") {
                    stream.read_exact(&mut byte).expect("headers");
                    request.push(byte[0]);
                }
                let request = String::from_utf8(request)
                    .expect("header text")
                    .to_lowercase();
                assert_eq!(request.contains("if-none-match:"), index == 1);
                let response = if index == 1 {
                    "HTTP/1.1 304 Not Modified\r\nConnection: close\r\n\r\n".to_owned()
                } else {
                    let body = "version: 1\nfiles: []\n";
                    format!(
                        "HTTP/1.1 200 OK\r\nETag: \"snapshot\"\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                        body.len()
                    )
                };
                stream.write_all(response.as_bytes()).expect("response");
            }
        });
        let runtime = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .expect("runtime");
        let mut remote = RemoteManifest::new(url, "token").expect("client");
        runtime.block_on(async {
            assert!(remote.fetch().await.expect("first fetch").is_some());
            assert!(remote.fetch().await.expect("unchanged").is_none());
            remote.reject();
            assert!(
                remote
                    .fetch()
                    .await
                    .expect("retry rejected manifest")
                    .is_some()
            );
        });
        server.join().expect("server");
    }
}
