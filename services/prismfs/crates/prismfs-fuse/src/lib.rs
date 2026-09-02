//! Linux FUSE adapter boundary for PrismFS.

use std::{path::PathBuf, sync::Arc};

use fuser::MountOption;
use prismfs_cache::ObjectCache;
use prismfs_core::Namespace;
use prismfs_policy::AccessPolicy;
use prismfs_storage::ObjectReader;
use thiserror::Error;

/// FUSE mount configuration independent of the server CLI.
#[derive(Clone, Debug)]
pub struct FuseConfig {
    /// Directory at which PrismFS will be mounted.
    pub mountpoint: PathBuf,
    /// Name reported to the kernel and mount tooling.
    pub filesystem_name: String,
    /// Whether mutating operations are rejected.
    pub read_only: bool,
}

impl FuseConfig {
    /// Creates the default read-only PrismFS configuration.
    #[must_use]
    pub fn read_only(mountpoint: impl Into<PathBuf>) -> Self {
        Self {
            mountpoint: mountpoint.into(),
            filesystem_name: "prismfs".to_owned(),
            read_only: true,
        }
    }

    /// Produces the kernel mount options owned by this adapter.
    #[must_use]
    pub fn mount_options(&self) -> Vec<MountOption> {
        let mut options = vec![
            MountOption::FSName(self.filesystem_name.clone()),
            MountOption::DefaultPermissions,
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
    /// Request callbacks are the next implementation slice.
    #[error("FUSE callbacks are not implemented yet")]
    AdapterNotReady,
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

    /// Starts the adapter once the filesystem callback slice is implemented.
    pub fn mount(self) -> Result<(), FuseError> {
        self.validate()?;
        let _components = (self.namespace, self.storage, self.policy, self.cache);
        Err(FuseError::AdapterNotReady)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn read_only_config_enables_kernel_permissions() {
        let config = FuseConfig::read_only("/tmp");
        let options = config.mount_options();
        assert!(options.contains(&MountOption::RO));
        assert!(options.contains(&MountOption::DefaultPermissions));
    }
}
