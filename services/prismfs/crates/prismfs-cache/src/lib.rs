//! Cache contracts independent of the FUSE and storage adapters.

use async_trait::async_trait;
use bytes::Bytes;
use dashmap::DashMap;
use prismfs_core::{ByteRange, ObjectRef, Result};

/// Complete identity of one cached object range.
#[derive(Clone, Debug, Eq, Hash, PartialEq)]
pub struct CacheKey {
    /// Backing object identity.
    pub object: ObjectRef,
    /// Cached byte range.
    pub range: ByteRange,
}

/// Cache behavior consumed by filesystem adapters.
#[async_trait]
pub trait ObjectCache: Send + Sync {
    /// Retrieves a cached range.
    async fn get(&self, key: &CacheKey) -> Result<Option<Bytes>>;

    /// Stores a range.
    async fn put(&self, key: CacheKey, value: Bytes) -> Result<()>;

    /// Removes all cached ranges for an object.
    async fn invalidate(&self, object: &ObjectRef) -> Result<()>;
}

/// Cache implementation that always misses.
#[derive(Clone, Copy, Debug, Default)]
pub struct NoCache;

#[async_trait]
impl ObjectCache for NoCache {
    async fn get(&self, _key: &CacheKey) -> Result<Option<Bytes>> {
        Ok(None)
    }

    async fn put(&self, _key: CacheKey, _value: Bytes) -> Result<()> {
        Ok(())
    }

    async fn invalidate(&self, _object: &ObjectRef) -> Result<()> {
        Ok(())
    }
}

/// Concurrent in-process cache for development and unit tests.
#[derive(Debug, Default)]
pub struct MemoryCache {
    entries: DashMap<CacheKey, Bytes>,
}

impl MemoryCache {
    /// Returns the current entry count.
    #[must_use]
    pub fn len(&self) -> usize {
        self.entries.len()
    }

    /// Returns whether the cache is empty.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }
}

#[async_trait]
impl ObjectCache for MemoryCache {
    async fn get(&self, key: &CacheKey) -> Result<Option<Bytes>> {
        Ok(self.entries.get(key).map(|entry| entry.value().clone()))
    }

    async fn put(&self, key: CacheKey, value: Bytes) -> Result<()> {
        self.entries.insert(key, value);
        Ok(())
    }

    async fn invalidate(&self, object: &ObjectRef) -> Result<()> {
        self.entries.retain(|key, _| &key.object != object);
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn object() -> ObjectRef {
        ObjectRef {
            bucket: "bucket".to_owned(),
            key: "key".to_owned(),
            size: 4,
            version: Some("v1".to_owned()),
        }
    }

    #[tokio::test]
    async fn invalidation_removes_every_range_for_an_object() {
        let cache = MemoryCache::default();
        let object = object();
        let key = CacheKey {
            object: object.clone(),
            range: ByteRange::new(0, 4).expect("valid range"),
        };
        cache
            .put(key, Bytes::from_static(b"data"))
            .await
            .expect("cache fixture");

        cache.invalidate(&object).await.expect("invalidate fixture");

        assert!(cache.is_empty());
    }
}
