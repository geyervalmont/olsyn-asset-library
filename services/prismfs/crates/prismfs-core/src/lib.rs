//! Protocol-independent namespace contracts and domain types for PrismFS.

use std::{collections::BTreeMap, fmt, sync::RwLock};

use async_trait::async_trait;
use serde::{Deserialize, Serialize};
use thiserror::Error;
use uuid::Uuid;

/// Result type shared by protocol-independent PrismFS crates.
pub type Result<T> = std::result::Result<T, PrismError>;

/// Failures that can cross a PrismFS core boundary.
#[derive(Debug, Error)]
pub enum PrismError {
    /// A virtual path is malformed.
    #[error("invalid virtual path: {0}")]
    InvalidPath(String),
    /// A namespace node was not found.
    #[error("namespace node not found: {0}")]
    NotFound(VirtualPath),
    /// A directory operation targeted a non-directory node.
    #[error("not a directory: {0}")]
    NotDirectory(VirtualPath),
    /// Policy rejected an operation.
    #[error("access denied: {0}")]
    AccessDenied(String),
    /// An object-store adapter failed.
    #[error("storage error: {0}")]
    Storage(String),
    /// An internal invariant or dependency failed.
    #[error("internal error: {0}")]
    Internal(String),
}

/// Canonical absolute path in a projected PrismFS namespace.
#[derive(Clone, Debug, Eq, Hash, Ord, PartialEq, PartialOrd, Serialize, Deserialize)]
#[serde(try_from = "String", into = "String")]
pub struct VirtualPath(String);

impl VirtualPath {
    /// Returns the namespace root.
    #[must_use]
    pub fn root() -> Self {
        Self("/".to_owned())
    }

    /// Parses a strict, canonical absolute virtual path.
    pub fn parse(value: impl Into<String>) -> Result<Self> {
        let value = value.into();
        if !value.starts_with('/') {
            return Err(PrismError::InvalidPath(value));
        }
        if value.len() > 1 && value.ends_with('/') {
            return Err(PrismError::InvalidPath(value));
        }

        for component in value.split('/').skip(1) {
            if component.is_empty()
                || component == "."
                || component == ".."
                || component.contains('\0')
            {
                return Err(PrismError::InvalidPath(value));
            }
        }

        Ok(Self(value))
    }

    /// Returns the canonical path string.
    #[must_use]
    pub fn as_str(&self) -> &str {
        &self.0
    }

    /// Returns this path's final component, or `/` for the root.
    #[must_use]
    pub fn file_name(&self) -> &str {
        self.0
            .rsplit('/')
            .next()
            .filter(|part| !part.is_empty())
            .unwrap_or("/")
    }

    /// Returns the parent path, or `None` for the root.
    #[must_use]
    pub fn parent(&self) -> Option<Self> {
        if self.0 == "/" {
            return None;
        }

        let boundary = self.0.rfind('/').unwrap_or(0);
        if boundary == 0 {
            Some(Self::root())
        } else {
            Some(Self(self.0[..boundary].to_owned()))
        }
    }

    /// Appends one validated path component.
    pub fn join(&self, component: &str) -> Result<Self> {
        if component.is_empty()
            || component == "."
            || component == ".."
            || component.contains(['/', '\0'])
        {
            return Err(PrismError::InvalidPath(component.to_owned()));
        }

        let joined = if self.0 == "/" {
            format!("/{component}")
        } else {
            format!("{}/{component}", self.0)
        };
        Ok(Self(joined))
    }
}

impl fmt::Display for VirtualPath {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str(&self.0)
    }
}

impl TryFrom<String> for VirtualPath {
    type Error = PrismError;

    fn try_from(value: String) -> Result<Self> {
        Self::parse(value)
    }
}

impl From<VirtualPath> for String {
    fn from(path: VirtualPath) -> Self {
        path.0
    }
}

/// Stable logical identifier for a projected node.
#[derive(Clone, Copy, Debug, Eq, Hash, PartialEq, Serialize, Deserialize)]
pub struct NodeId(Uuid);

impl NodeId {
    /// Creates a new identifier.
    #[must_use]
    pub fn new() -> Self {
        Self(Uuid::new_v4())
    }
}

impl Default for NodeId {
    fn default() -> Self {
        Self::new()
    }
}

/// Kind of namespace node.
#[derive(Clone, Copy, Debug, Eq, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum NodeKind {
    /// Directory containing projected children.
    Directory,
    /// File whose bytes resolve through an object reference.
    File,
}

/// Reference to immutable bytes in an object store.
#[derive(Clone, Debug, Eq, Hash, PartialEq, Serialize, Deserialize)]
pub struct ObjectRef {
    /// Object-store bucket.
    pub bucket: String,
    /// Object key within the bucket.
    pub key: String,
    /// Expected object length.
    pub size: u64,
    /// Optional immutable version or entity tag.
    pub version: Option<String>,
}

/// A node in a computed PrismFS namespace.
#[derive(Clone, Debug, Eq, PartialEq, Serialize, Deserialize)]
pub struct Node {
    /// Stable logical identifier.
    pub id: NodeId,
    /// Name within the parent directory.
    pub name: String,
    /// File or directory.
    pub kind: NodeKind,
    /// Byte size; zero for directories.
    pub size: u64,
    /// Backing object for files.
    pub object: Option<ObjectRef>,
}

impl Node {
    /// Creates a directory node.
    #[must_use]
    pub fn directory(name: impl Into<String>) -> Self {
        Self {
            id: NodeId::new(),
            name: name.into(),
            kind: NodeKind::Directory,
            size: 0,
            object: None,
        }
    }

    /// Creates a file node backed by an object.
    #[must_use]
    pub fn file(name: impl Into<String>, object: ObjectRef) -> Self {
        Self {
            id: NodeId::new(),
            name: name.into(),
            kind: NodeKind::File,
            size: object.size,
            object: Some(object),
        }
    }
}

/// Inclusive-start, exclusive-end object byte range.
#[derive(Clone, Copy, Debug, Eq, Hash, PartialEq, Serialize, Deserialize)]
pub struct ByteRange {
    /// First byte offset.
    pub start: u64,
    /// Exclusive end offset.
    pub end: u64,
}

impl ByteRange {
    /// Creates a validated range.
    pub fn new(start: u64, end: u64) -> Result<Self> {
        if start > end {
            return Err(PrismError::Storage(format!(
                "range start {start} exceeds end {end}"
            )));
        }
        Ok(Self { start, end })
    }

    /// Returns the number of bytes in the range.
    #[must_use]
    pub fn len(self) -> u64 {
        self.end - self.start
    }

    /// Returns whether this range contains no bytes.
    #[must_use]
    pub fn is_empty(self) -> bool {
        self.start == self.end
    }
}

/// Tenant and principal information attached to one filesystem operation.
#[derive(Clone, Debug, Eq, PartialEq, Serialize, Deserialize)]
pub struct RequestContext {
    /// Correlation identifier propagated to logs and traces.
    pub request_id: Uuid,
    /// Tenant whose view is being projected.
    pub tenant_id: String,
    /// User or service principal performing the operation.
    pub principal_id: String,
}

impl RequestContext {
    /// Creates a new operation context.
    #[must_use]
    pub fn new(tenant_id: impl Into<String>, principal_id: impl Into<String>) -> Self {
        Self {
            request_id: Uuid::new_v4(),
            tenant_id: tenant_id.into(),
            principal_id: principal_id.into(),
        }
    }
}

/// Resolves a contextual, virtual filesystem namespace.
#[async_trait]
pub trait Namespace: Send + Sync {
    /// Looks up one path.
    async fn lookup(&self, context: &RequestContext, path: &VirtualPath) -> Result<Node>;

    /// Lists immediate children of a directory.
    async fn list(&self, context: &RequestContext, path: &VirtualPath) -> Result<Vec<Node>>;
}

/// Deterministic namespace used by unit tests and the initial server scaffold.
#[derive(Debug)]
pub struct StaticNamespace {
    nodes: RwLock<BTreeMap<VirtualPath, Node>>,
}

impl StaticNamespace {
    /// Creates a namespace containing only its root.
    #[must_use]
    pub fn new() -> Self {
        let mut nodes = BTreeMap::new();
        nodes.insert(VirtualPath::root(), Node::directory("/"));
        Self {
            nodes: RwLock::new(nodes),
        }
    }

    /// Inserts or replaces a node at a canonical path.
    pub fn insert(&self, path: VirtualPath, node: Node) -> Result<()> {
        let mut nodes = self
            .nodes
            .write()
            .map_err(|error| PrismError::Internal(error.to_string()))?;
        nodes.insert(path, node);
        Ok(())
    }
}

impl Default for StaticNamespace {
    fn default() -> Self {
        Self::new()
    }
}

#[async_trait]
impl Namespace for StaticNamespace {
    async fn lookup(&self, _context: &RequestContext, path: &VirtualPath) -> Result<Node> {
        self.nodes
            .read()
            .map_err(|error| PrismError::Internal(error.to_string()))?
            .get(path)
            .cloned()
            .ok_or_else(|| PrismError::NotFound(path.clone()))
    }

    async fn list(&self, context: &RequestContext, path: &VirtualPath) -> Result<Vec<Node>> {
        let directory = self.lookup(context, path).await?;
        if directory.kind != NodeKind::Directory {
            return Err(PrismError::NotDirectory(path.clone()));
        }

        let nodes = self
            .nodes
            .read()
            .map_err(|error| PrismError::Internal(error.to_string()))?;
        Ok(nodes
            .iter()
            .filter(|(candidate, _)| candidate.parent().as_ref() == Some(path))
            .map(|(_, node)| node.clone())
            .collect())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn paths_are_canonical() {
        assert!(VirtualPath::parse("/materials/concrete").is_ok());
        assert!(VirtualPath::parse("materials").is_err());
        assert!(VirtualPath::parse("/materials/../secret").is_err());
        assert!(VirtualPath::parse("/materials//concrete").is_err());
    }

    #[tokio::test]
    async fn static_namespace_lists_immediate_children() {
        let namespace = StaticNamespace::new();
        let materials = VirtualPath::parse("/materials").expect("valid fixture path");
        namespace
            .insert(materials.clone(), Node::directory("materials"))
            .expect("insert fixture node");
        namespace
            .insert(
                materials.join("concrete").expect("valid child path"),
                Node::directory("concrete"),
            )
            .expect("insert fixture child");

        let children = namespace
            .list(&RequestContext::new("tenant", "user"), &materials)
            .await
            .expect("list fixture directory");

        assert_eq!(children.len(), 1);
        assert_eq!(children[0].name, "concrete");
    }
}
