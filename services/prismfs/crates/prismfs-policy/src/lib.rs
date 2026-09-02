//! Authorization contracts for namespace and object operations.

use async_trait::async_trait;
use prismfs_core::{Node, RequestContext, Result, VirtualPath};
use serde::{Deserialize, Serialize};

/// Filesystem operation subject to policy.
#[derive(Clone, Copy, Debug, Eq, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Action {
    /// Resolve a path.
    Lookup,
    /// List a directory.
    List,
    /// Open a file.
    Open,
    /// Read file bytes.
    Read,
}

/// Explicit authorization outcome.
#[derive(Clone, Debug, Eq, PartialEq, Serialize, Deserialize)]
#[serde(tag = "result", rename_all = "snake_case")]
pub enum Decision {
    /// Permit the operation.
    Allow,
    /// Reject the operation with an audit-safe reason.
    Deny {
        /// Human-readable policy reason.
        reason: String,
    },
}

impl Decision {
    /// Returns whether the operation is permitted.
    #[must_use]
    pub fn is_allowed(&self) -> bool {
        matches!(self, Self::Allow)
    }
}

/// Authorizes contextual filesystem operations independently of FUSE or SMB.
#[async_trait]
pub trait AccessPolicy: Send + Sync {
    /// Produces one explicit decision.
    async fn authorize(
        &self,
        context: &RequestContext,
        action: Action,
        path: &VirtualPath,
        node: Option<&Node>,
    ) -> Result<Decision>;
}

/// Development policy that permits every operation.
#[derive(Clone, Copy, Debug, Default)]
pub struct AllowAll;

#[async_trait]
impl AccessPolicy for AllowAll {
    async fn authorize(
        &self,
        _context: &RequestContext,
        _action: Action,
        _path: &VirtualPath,
        _node: Option<&Node>,
    ) -> Result<Decision> {
        Ok(Decision::Allow)
    }
}

/// Development policy that rejects every operation.
#[derive(Clone, Debug)]
pub struct DenyAll {
    reason: String,
}

/// Policy that rejects paths at or beneath configured virtual prefixes.
#[derive(Clone, Debug, Default)]
pub struct DenyPrefixes {
    prefixes: Vec<VirtualPath>,
}

impl DenyPrefixes {
    /// Creates a prefix policy. An empty set permits every operation.
    #[must_use]
    pub fn new(prefixes: Vec<VirtualPath>) -> Self {
        Self { prefixes }
    }

    fn denied_prefix<'a>(&'a self, path: &VirtualPath) -> Option<&'a VirtualPath> {
        self.prefixes.iter().find(|prefix| {
            path == *prefix
                || prefix.as_str() == "/"
                || path
                    .as_str()
                    .strip_prefix(prefix.as_str())
                    .is_some_and(|suffix| suffix.starts_with('/'))
        })
    }
}

#[async_trait]
impl AccessPolicy for DenyPrefixes {
    async fn authorize(
        &self,
        _context: &RequestContext,
        _action: Action,
        path: &VirtualPath,
        _node: Option<&Node>,
    ) -> Result<Decision> {
        Ok(match self.denied_prefix(path) {
            Some(prefix) => Decision::Deny {
                reason: format!("path is denied by prefix {prefix}"),
            },
            None => Decision::Allow,
        })
    }
}

impl DenyAll {
    /// Creates a deny-all policy with an audit-safe reason.
    #[must_use]
    pub fn new(reason: impl Into<String>) -> Self {
        Self {
            reason: reason.into(),
        }
    }
}

#[async_trait]
impl AccessPolicy for DenyAll {
    async fn authorize(
        &self,
        _context: &RequestContext,
        _action: Action,
        _path: &VirtualPath,
        _node: Option<&Node>,
    ) -> Result<Decision> {
        Ok(Decision::Deny {
            reason: self.reason.clone(),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn allow_all_is_explicit() {
        let decision = AllowAll
            .authorize(
                &RequestContext::new("tenant", "user"),
                Action::Lookup,
                &VirtualPath::root(),
                None,
            )
            .await
            .expect("policy should answer");
        assert_eq!(decision, Decision::Allow);
    }

    #[tokio::test]
    async fn prefix_policy_denies_descendants_only() {
        let policy = DenyPrefixes::new(vec![
            VirtualPath::parse("/private").expect("valid policy path"),
        ]);
        let context = RequestContext::new("tenant", "user");

        let denied = policy
            .authorize(
                &context,
                Action::Read,
                &VirtualPath::parse("/private/file.txt").expect("valid path"),
                None,
            )
            .await
            .expect("policy decision");
        let allowed = policy
            .authorize(
                &context,
                Action::Read,
                &VirtualPath::parse("/private-not/file.txt").expect("valid path"),
                None,
            )
            .await
            .expect("policy decision");

        assert!(!denied.is_allowed());
        assert!(allowed.is_allowed());
    }
}
