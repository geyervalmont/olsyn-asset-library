//! Read-only Linux FUSE adapter for PrismFS.

use std::{
    collections::HashMap,
    ffi::OsStr,
    path::PathBuf,
    sync::{
        Arc, RwLock,
        atomic::{AtomicU64, Ordering},
    },
    time::{Duration, Instant, SystemTime},
};

use bytes::Bytes;
use fuser::{
    BsdFileFlags, Config, Errno, FileAttr, FileHandle, FileType, Filesystem, FopenFlags,
    Generation, INodeNo, LockOwner, MountOption, OpenAccMode, OpenFlags, RenameFlags, ReplyAttr,
    ReplyData, ReplyDirectory, ReplyEmpty, ReplyEntry, ReplyOpen, ReplyStatfs, ReplyWrite, Request,
    SessionACL, TimeOrNow, WriteFlags,
};
use prismfs_cache::{CacheKey, ObjectCache};
use prismfs_core::{ByteRange, Namespace, Node, NodeKind, PrismError, RequestContext, VirtualPath};
use prismfs_policy::{AccessPolicy, Action, Decision};
use prismfs_storage::ObjectReader;
use prismfs_telemetry::{AuditEvent, record, record_cache, record_file_handle};
use thiserror::Error;
use tokio::runtime::{Builder, Runtime};

const ATTRIBUTE_TTL: Duration = Duration::from_secs(1);

/// FUSE mount configuration independent of the server CLI.
#[derive(Clone, Debug)]
pub struct FuseConfig {
    /// Directory at which PrismFS will be mounted.
    pub mountpoint: PathBuf,
    /// Name reported to the kernel and mount tooling.
    pub filesystem_name: String,
    /// Tenant attached to filesystem operations.
    pub tenant_id: String,
    /// Whether mutating operations are rejected.
    pub read_only: bool,
    /// Whether the kernel page cache is bypassed for file reads.
    pub direct_io: bool,
}

impl FuseConfig {
    /// Creates the default read-only PrismFS configuration.
    #[must_use]
    pub fn read_only(mountpoint: impl Into<PathBuf>) -> Self {
        Self {
            mountpoint: mountpoint.into(),
            filesystem_name: "prismfs".to_owned(),
            tenant_id: "local".to_owned(),
            read_only: true,
            direct_io: true,
        }
    }

    /// Produces the kernel mount options owned by this adapter.
    #[must_use]
    pub fn mount_options(&self) -> Vec<MountOption> {
        let mut options = vec![
            MountOption::FSName(self.filesystem_name.clone()),
            MountOption::Subtype("prismfs".to_owned()),
            MountOption::DefaultPermissions,
            MountOption::NoDev,
            MountOption::NoSuid,
            MountOption::NoExec,
            MountOption::NoAtime,
        ];
        if self.read_only {
            options.push(MountOption::RO);
        }
        options
    }
}

/// FUSE adapter lifecycle errors.
#[derive(Debug, Error)]
pub enum FuseError {
    /// The mountpoint is missing or is not a directory.
    #[error("mountpoint is not a directory: {0}")]
    InvalidMountpoint(PathBuf),
    /// The callback runtime could not be created.
    #[error("failed to create the FUSE runtime: {0}")]
    Runtime(#[source] std::io::Error),
    /// The FUSE session could not start or ended with an error.
    #[error("FUSE mount failed: {0}")]
    Mount(#[source] std::io::Error),
}

/// Composition boundary connecting FUSE to core services.
pub struct FuseAdapter {
    config: FuseConfig,
    namespace: Arc<dyn Namespace>,
    storage: Arc<dyn ObjectReader>,
    policy: Arc<dyn AccessPolicy>,
    cache: Arc<dyn ObjectCache>,
}

impl FuseAdapter {
    /// Creates an adapter without coupling the core to FUSE types.
    #[must_use]
    pub fn new(
        config: FuseConfig,
        namespace: Arc<dyn Namespace>,
        storage: Arc<dyn ObjectReader>,
        policy: Arc<dyn AccessPolicy>,
        cache: Arc<dyn ObjectCache>,
    ) -> Self {
        Self {
            config,
            namespace,
            storage,
            policy,
            cache,
        }
    }

    /// Validates prerequisites that do not require mounting `/dev/fuse`.
    pub fn validate(&self) -> Result<(), FuseError> {
        if !self.config.mountpoint.is_dir() {
            return Err(FuseError::InvalidMountpoint(self.config.mountpoint.clone()));
        }
        Ok(())
    }

    /// Returns the mount configuration.
    #[must_use]
    pub fn config(&self) -> &FuseConfig {
        &self.config
    }

    /// Mounts PrismFS and serves requests until the filesystem is unmounted.
    pub fn mount(self) -> Result<(), FuseError> {
        self.validate()?;
        let runtime = Builder::new_multi_thread()
            .worker_threads(4)
            .enable_all()
            .build()
            .map_err(FuseError::Runtime)?;
        let mountpoint = self.config.mountpoint.clone();
        let mut options = Config::default();
        options.mount_options = self.config.mount_options();
        options.acl = SessionACL::Owner;
        options.n_threads = Some(4);
        options.clone_fd = true;
        let filesystem = PrismFilesystem::new(
            runtime,
            self.config,
            self.namespace,
            self.storage,
            self.policy,
            self.cache,
        );

        fuser::mount(filesystem, mountpoint, &options).map_err(FuseError::Mount)
    }
}

struct InodeTable {
    next: AtomicU64,
    by_inode: RwLock<HashMap<INodeNo, VirtualPath>>,
    by_path: RwLock<HashMap<VirtualPath, INodeNo>>,
}

impl InodeTable {
    fn new() -> Self {
        let root = VirtualPath::root();
        Self {
            next: AtomicU64::new(INodeNo::ROOT.0 + 1),
            by_inode: RwLock::new(HashMap::from([(INodeNo::ROOT, root.clone())])),
            by_path: RwLock::new(HashMap::from([(root, INodeNo::ROOT)])),
        }
    }

    fn path(&self, inode: INodeNo) -> Option<VirtualPath> {
        self.by_inode.read().ok()?.get(&inode).cloned()
    }

    fn ensure(&self, path: &VirtualPath) -> Result<INodeNo, PrismError> {
        if let Some(inode) = self
            .by_path
            .read()
            .map_err(|error| PrismError::Internal(error.to_string()))?
            .get(path)
            .copied()
        {
            return Ok(inode);
        }

        let mut by_path = self
            .by_path
            .write()
            .map_err(|error| PrismError::Internal(error.to_string()))?;
        if let Some(inode) = by_path.get(path).copied() {
            return Ok(inode);
        }
        let inode = INodeNo(self.next.fetch_add(1, Ordering::Relaxed));
        by_path.insert(path.clone(), inode);
        self.by_inode
            .write()
            .map_err(|error| PrismError::Internal(error.to_string()))?
            .insert(inode, path.clone());
        Ok(inode)
    }
}

struct ReadPipeline {
    storage: Arc<dyn ObjectReader>,
    policy: Arc<dyn AccessPolicy>,
    cache: Arc<dyn ObjectCache>,
}

impl ReadPipeline {
    async fn read(
        &self,
        context: &RequestContext,
        path: &VirtualPath,
        node: &Node,
        range: ByteRange,
    ) -> Result<Bytes, PrismError> {
        authorize(
            self.policy.as_ref(),
            context,
            Action::Read,
            path,
            Some(node),
        )
        .await?;
        let object = node.object.as_ref().ok_or_else(|| {
            PrismError::Internal(format!("file node {path} has no backing object"))
        })?;
        let key = CacheKey {
            object: object.clone(),
            range,
        };
        if let Some(bytes) = self.cache.get(&key).await? {
            record_cache(true, bytes.len());
            return Ok(bytes);
        }

        let bytes = self.storage.read_range(context, object, range).await?;
        self.cache.put(key, bytes.clone()).await?;
        record_cache(false, bytes.len());
        Ok(bytes)
    }
}

struct PrismFilesystem {
    runtime: Runtime,
    config: FuseConfig,
    namespace: Arc<dyn Namespace>,
    policy: Arc<dyn AccessPolicy>,
    pipeline: ReadPipeline,
    inodes: InodeTable,
}

impl PrismFilesystem {
    fn new(
        runtime: Runtime,
        config: FuseConfig,
        namespace: Arc<dyn Namespace>,
        storage: Arc<dyn ObjectReader>,
        policy: Arc<dyn AccessPolicy>,
        cache: Arc<dyn ObjectCache>,
    ) -> Self {
        Self {
            runtime,
            config,
            namespace,
            policy: Arc::clone(&policy),
            pipeline: ReadPipeline {
                storage,
                policy,
                cache,
            },
            inodes: InodeTable::new(),
        }
    }

    fn context(&self, request: &Request) -> RequestContext {
        RequestContext::new(&self.config.tenant_id, format!("uid:{}", request.uid()))
    }

    fn path(&self, inode: INodeNo) -> Result<VirtualPath, PrismError> {
        self.inodes.path(inode).ok_or_else(|| {
            PrismError::NotFound(
                VirtualPath::parse(format!("/.inode-{}", inode.0))
                    .expect("synthetic inode path is valid"),
            )
        })
    }

    fn attr(&self, inode: INodeNo, node: &Node) -> FileAttr {
        let directory = node.kind == NodeKind::Directory;
        FileAttr {
            ino: inode,
            size: node.size,
            blocks: node.size.div_ceil(512),
            atime: SystemTime::UNIX_EPOCH,
            mtime: SystemTime::UNIX_EPOCH,
            ctime: SystemTime::UNIX_EPOCH,
            crtime: SystemTime::UNIX_EPOCH,
            kind: if directory {
                FileType::Directory
            } else {
                FileType::RegularFile
            },
            perm: if directory { 0o555 } else { 0o444 },
            nlink: if directory { 2 } else { 1 },
            uid: 0,
            gid: 0,
            rdev: 0,
            blksize: 4096,
            flags: 0,
        }
    }

    fn audit(
        &self,
        context: &RequestContext,
        operation: &str,
        path: &VirtualPath,
        result: &str,
        started: Instant,
    ) {
        self.audit_bytes(context, operation, path, result, None, started);
    }

    fn audit_bytes(
        &self,
        context: &RequestContext,
        operation: &str,
        path: &VirtualPath,
        result: &str,
        bytes: Option<u64>,
        started: Instant,
    ) {
        record(&AuditEvent {
            context,
            operation,
            path,
            result,
            bytes,
            duration_ms: started.elapsed().as_secs_f64() * 1_000.0,
        });
    }
}

impl Filesystem for PrismFilesystem {
    fn lookup(&self, request: &Request, parent: INodeNo, name: &OsStr, reply: ReplyEntry) {
        let context = self.context(request);
        let started = Instant::now();
        let attempted_path = (|| {
            let parent_path = self.path(parent)?;
            let name = name
                .to_str()
                .ok_or_else(|| PrismError::InvalidPath(name.to_string_lossy().into_owned()))?;
            parent_path.join(name)
        })();
        let audit_path = attempted_path
            .as_ref()
            .cloned()
            .unwrap_or_else(|_| self.path(parent).unwrap_or_else(|_| VirtualPath::root()));
        let result = attempted_path.and_then(|path| {
            let node = self
                .runtime
                .block_on(self.namespace.lookup(&context, &path))?;
            self.runtime.block_on(authorize(
                self.policy.as_ref(),
                &context,
                Action::Lookup,
                &path,
                Some(&node),
            ))?;
            let inode = self.inodes.ensure(&path)?;
            Ok((path, inode, node))
        });

        match result {
            Ok((path, inode, node)) => {
                self.audit(&context, "lookup", &path, "allow", started);
                reply.entry(&ATTRIBUTE_TTL, &self.attr(inode, &node), Generation(0));
            }
            Err(error) => {
                self.audit(
                    &context,
                    "lookup",
                    &audit_path,
                    result_label(&error),
                    started,
                );
                reply.error(errno(&error));
            }
        }
    }

    fn getattr(
        &self,
        request: &Request,
        inode: INodeNo,
        _handle: Option<FileHandle>,
        reply: ReplyAttr,
    ) {
        let context = self.context(request);
        let started = Instant::now();
        let result = (|| {
            let path = self.path(inode)?;
            let node = self
                .runtime
                .block_on(self.namespace.lookup(&context, &path))?;
            self.runtime.block_on(authorize(
                self.policy.as_ref(),
                &context,
                Action::Lookup,
                &path,
                Some(&node),
            ))?;
            Ok((path, node))
        })();

        match result {
            Ok((path, node)) => {
                self.audit(&context, "getattr", &path, "allow", started);
                reply.attr(&ATTRIBUTE_TTL, &self.attr(inode, &node));
            }
            Err(error) => {
                let path = self.path(inode).unwrap_or_else(|_| VirtualPath::root());
                self.audit(&context, "getattr", &path, result_label(&error), started);
                reply.error(errno(&error));
            }
        }
    }

    fn open(&self, request: &Request, inode: INodeNo, flags: OpenFlags, reply: ReplyOpen) {
        if flags.acc_mode() != OpenAccMode::O_RDONLY {
            reply.error(Errno::EROFS);
            return;
        }
        let context = self.context(request);
        let started = Instant::now();
        let result = (|| {
            let path = self.path(inode)?;
            let node = self
                .runtime
                .block_on(self.namespace.lookup(&context, &path))?;
            if node.kind == NodeKind::Directory {
                return Err(PrismError::NotDirectory(path.clone()));
            }
            self.runtime.block_on(authorize(
                self.policy.as_ref(),
                &context,
                Action::Open,
                &path,
                Some(&node),
            ))?;
            Ok(path)
        })();
        match result {
            Ok(path) => {
                self.audit(&context, "open", &path, "allow", started);
                record_file_handle(1.0);
                let open_flags = if self.config.direct_io {
                    FopenFlags::FOPEN_DIRECT_IO
                } else {
                    FopenFlags::empty()
                };
                reply.opened(FileHandle(inode.0), open_flags);
            }
            Err(error) => {
                let path = self.path(inode).unwrap_or_else(|_| VirtualPath::root());
                self.audit(&context, "open", &path, result_label(&error), started);
                reply.error(errno(&error));
            }
        }
    }

    fn read(
        &self,
        request: &Request,
        inode: INodeNo,
        handle: FileHandle,
        offset: u64,
        size: u32,
        _flags: OpenFlags,
        _lock_owner: Option<LockOwner>,
        reply: ReplyData,
    ) {
        if handle.0 != inode.0 {
            reply.error(Errno::EBADF);
            return;
        }
        let context = self.context(request);
        let started = Instant::now();
        let result = (|| {
            let path = self.path(inode)?;
            let node = self
                .runtime
                .block_on(self.namespace.lookup(&context, &path))?;
            if node.kind != NodeKind::File {
                return Err(PrismError::NotDirectory(path));
            }
            if offset >= node.size {
                self.runtime.block_on(authorize(
                    self.policy.as_ref(),
                    &context,
                    Action::Read,
                    &path,
                    Some(&node),
                ))?;
                return Ok((path, Bytes::new()));
            }
            let end = offset.saturating_add(u64::from(size)).min(node.size);
            let range = ByteRange::new(offset, end)?;
            let bytes = self
                .runtime
                .block_on(self.pipeline.read(&context, &path, &node, range))?;
            Ok((path, bytes))
        })();

        match result {
            Ok((path, bytes)) => {
                self.audit_bytes(
                    &context,
                    "read",
                    &path,
                    "allow",
                    Some(bytes.len() as u64),
                    started,
                );
                reply.data(&bytes);
            }
            Err(error) => {
                let path = self.path(inode).unwrap_or_else(|_| VirtualPath::root());
                self.audit(&context, "read", &path, result_label(&error), started);
                reply.error(errno(&error));
            }
        }
    }

    fn release(
        &self,
        _request: &Request,
        _inode: INodeNo,
        _handle: FileHandle,
        _flags: OpenFlags,
        _lock_owner: Option<LockOwner>,
        _flush: bool,
        reply: ReplyEmpty,
    ) {
        record_file_handle(-1.0);
        reply.ok();
    }

    fn opendir(&self, request: &Request, inode: INodeNo, _flags: OpenFlags, reply: ReplyOpen) {
        let context = self.context(request);
        let result = (|| {
            let path = self.path(inode)?;
            let node = self
                .runtime
                .block_on(self.namespace.lookup(&context, &path))?;
            if node.kind != NodeKind::Directory {
                return Err(PrismError::NotDirectory(path));
            }
            self.runtime.block_on(authorize(
                self.policy.as_ref(),
                &context,
                Action::List,
                &path,
                Some(&node),
            ))
        })();
        match result {
            Ok(()) => reply.opened(FileHandle(inode.0), FopenFlags::empty()),
            Err(error) => reply.error(errno(&error)),
        }
    }

    fn readdir(
        &self,
        request: &Request,
        inode: INodeNo,
        handle: FileHandle,
        offset: u64,
        mut reply: ReplyDirectory,
    ) {
        if handle.0 != inode.0 {
            reply.error(Errno::EBADF);
            return;
        }
        let context = self.context(request);
        let result = (|| {
            let path = self.path(inode)?;
            let node = self
                .runtime
                .block_on(self.namespace.lookup(&context, &path))?;
            if node.kind != NodeKind::Directory {
                return Err(PrismError::NotDirectory(path));
            }
            self.runtime.block_on(authorize(
                self.policy.as_ref(),
                &context,
                Action::List,
                &path,
                Some(&node),
            ))?;
            let children = self
                .runtime
                .block_on(self.namespace.list(&context, &path))?;
            let parent = path.parent().unwrap_or_else(VirtualPath::root);
            let parent_inode = self.inodes.ensure(&parent)?;
            let mut entries = vec![
                (inode, FileType::Directory, ".".to_owned()),
                (parent_inode, FileType::Directory, "..".to_owned()),
            ];
            for child in children {
                let child_path = path.join(&child.name)?;
                match self.runtime.block_on(self.policy.authorize(
                    &context,
                    Action::Lookup,
                    &child_path,
                    Some(&child),
                ))? {
                    Decision::Allow => {
                        let child_inode = self.inodes.ensure(&child_path)?;
                        let kind = if child.kind == NodeKind::Directory {
                            FileType::Directory
                        } else {
                            FileType::RegularFile
                        };
                        entries.push((child_inode, kind, child.name));
                    }
                    Decision::Deny { .. } => {}
                }
            }
            Ok(entries)
        })();

        match result {
            Ok(entries) => {
                let start = usize::try_from(offset).unwrap_or(usize::MAX);
                for (index, (entry_inode, kind, name)) in
                    entries.into_iter().enumerate().skip(start)
                {
                    if reply.add(entry_inode, (index + 1) as u64, kind, name) {
                        break;
                    }
                }
                reply.ok();
            }
            Err(error) => reply.error(errno(&error)),
        }
    }

    fn statfs(&self, _request: &Request, _inode: INodeNo, reply: ReplyStatfs) {
        reply.statfs(0, 0, 0, 0, 0, 4096, 255, 4096);
    }

    fn setattr(
        &self,
        _request: &Request,
        _inode: INodeNo,
        _mode: Option<u32>,
        _uid: Option<u32>,
        _gid: Option<u32>,
        _size: Option<u64>,
        _atime: Option<TimeOrNow>,
        _mtime: Option<TimeOrNow>,
        _ctime: Option<SystemTime>,
        _handle: Option<FileHandle>,
        _crtime: Option<SystemTime>,
        _chgtime: Option<SystemTime>,
        _bkuptime: Option<SystemTime>,
        _flags: Option<BsdFileFlags>,
        reply: ReplyAttr,
    ) {
        reply.error(Errno::EROFS);
    }

    fn mkdir(
        &self,
        _request: &Request,
        _parent: INodeNo,
        _name: &OsStr,
        _mode: u32,
        _umask: u32,
        reply: ReplyEntry,
    ) {
        reply.error(Errno::EROFS);
    }

    fn unlink(&self, _request: &Request, _parent: INodeNo, _name: &OsStr, reply: ReplyEmpty) {
        reply.error(Errno::EROFS);
    }

    fn rmdir(&self, _request: &Request, _parent: INodeNo, _name: &OsStr, reply: ReplyEmpty) {
        reply.error(Errno::EROFS);
    }

    fn rename(
        &self,
        _request: &Request,
        _parent: INodeNo,
        _name: &OsStr,
        _new_parent: INodeNo,
        _new_name: &OsStr,
        _flags: RenameFlags,
        reply: ReplyEmpty,
    ) {
        reply.error(Errno::EROFS);
    }

    fn write(
        &self,
        _request: &Request,
        _inode: INodeNo,
        _handle: FileHandle,
        _offset: u64,
        _data: &[u8],
        _write_flags: WriteFlags,
        _flags: OpenFlags,
        _lock_owner: Option<LockOwner>,
        reply: ReplyWrite,
    ) {
        reply.error(Errno::EROFS);
    }
}

async fn authorize(
    policy: &dyn AccessPolicy,
    context: &RequestContext,
    action: Action,
    path: &VirtualPath,
    node: Option<&Node>,
) -> Result<(), PrismError> {
    match policy.authorize(context, action, path, node).await? {
        Decision::Allow => Ok(()),
        Decision::Deny { reason } => Err(PrismError::AccessDenied(reason)),
    }
}

fn errno(error: &PrismError) -> Errno {
    match error {
        PrismError::NotFound(_) => Errno::ENOENT,
        PrismError::NotDirectory(_) => Errno::ENOTDIR,
        PrismError::AccessDenied(_) => Errno::EACCES,
        PrismError::InvalidPath(_) | PrismError::InvalidManifest(_) => Errno::EINVAL,
        PrismError::Storage(_) | PrismError::Internal(_) => Errno::EIO,
    }
}

fn result_label(error: &PrismError) -> &'static str {
    if matches!(error, PrismError::AccessDenied(_)) {
        "deny"
    } else {
        "error"
    }
}

#[cfg(test)]
mod tests {
    use std::sync::atomic::{AtomicUsize, Ordering};

    use prismfs_cache::MemoryCache;
    use prismfs_core::ObjectRef;
    use prismfs_policy::{AllowAll, DenyAll};
    use prismfs_storage::MemoryObjectReader;

    use super::*;

    fn file() -> (VirtualPath, Node, Arc<MemoryObjectReader>) {
        let path = VirtualPath::parse("/fixture.txt").expect("valid fixture path");
        let object = ObjectRef {
            bucket: "assets".to_owned(),
            key: "fixture.txt".to_owned(),
            size: 7,
            version: Some("v1".to_owned()),
        };
        let storage = Arc::new(MemoryObjectReader::default());
        storage.insert("assets", "fixture.txt", Bytes::from_static(b"prismfs"));
        (path, Node::file("fixture.txt", object), storage)
    }

    #[test]
    fn read_only_config_enables_safe_kernel_options() {
        let config = FuseConfig::read_only("/tmp");
        let options = config.mount_options();
        assert!(options.contains(&MountOption::RO));
        assert!(options.contains(&MountOption::DefaultPermissions));
        assert!(options.contains(&MountOption::NoDev));
        assert!(!options.contains(&MountOption::AutoUnmount));
    }

    #[tokio::test]
    async fn read_pipeline_caches_exact_ranges() {
        let (path, node, storage) = file();
        let cache = Arc::new(MemoryCache::default());
        let pipeline = ReadPipeline {
            storage,
            policy: Arc::new(AllowAll),
            cache: cache.clone(),
        };
        let context = RequestContext::new("tenant", "user");
        let range = ByteRange::new(0, 5).expect("valid range");

        let first = pipeline
            .read(&context, &path, &node, range)
            .await
            .expect("first read");
        let second = pipeline
            .read(&context, &path, &node, range)
            .await
            .expect("cached read");

        assert_eq!(first, Bytes::from_static(b"prism"));
        assert_eq!(second, first);
        assert_eq!(cache.len(), 1);
    }

    struct CountingReader(AtomicUsize);

    #[async_trait::async_trait]
    impl ObjectReader for CountingReader {
        async fn read_range(
            &self,
            _context: &RequestContext,
            _object: &ObjectRef,
            _range: ByteRange,
        ) -> prismfs_core::Result<Bytes> {
            self.0.fetch_add(1, Ordering::Relaxed);
            Ok(Bytes::new())
        }
    }

    #[tokio::test]
    async fn policy_denial_happens_before_storage() {
        let (path, node, _) = file();
        let storage = Arc::new(CountingReader(AtomicUsize::new(0)));
        let pipeline = ReadPipeline {
            storage: storage.clone(),
            policy: Arc::new(DenyAll::new("fixture denial")),
            cache: Arc::new(MemoryCache::default()),
        };

        let error = pipeline
            .read(
                &RequestContext::new("tenant", "user"),
                &path,
                &node,
                ByteRange::new(0, 1).expect("valid range"),
            )
            .await
            .expect_err("policy must deny read");

        assert!(matches!(error, PrismError::AccessDenied(_)));
        assert_eq!(storage.0.load(Ordering::Relaxed), 0);
    }
}
