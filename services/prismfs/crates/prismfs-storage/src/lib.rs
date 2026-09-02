//! Object-reading contracts and S3-compatible storage adapters.

use std::sync::Arc;

use async_trait::async_trait;
use bytes::Bytes;
use dashmap::DashMap;
use object_store::{ObjectStore, ObjectStoreExt, aws::AmazonS3Builder, path::Path};
use prismfs_core::{ByteRange, ObjectRef, PrismError, RequestContext, Result};

/// Reads immutable byte ranges without exposing a concrete SDK to the core.
#[async_trait]
pub trait ObjectReader: Send + Sync {
    /// Reads an inclusive-start, exclusive-end byte range.
    async fn read_range(
        &self,
        context: &RequestContext,
        object: &ObjectRef,
        range: ByteRange,
    ) -> Result<Bytes>;
}

/// Adapter around the `object_store` crate, scoped to one bucket.
#[derive(Debug)]
pub struct ObjectStoreReader {
    bucket: String,
    store: Arc<dyn ObjectStore>,
}

impl ObjectStoreReader {
    /// Wraps an already configured object store.
    #[must_use]
    pub fn new(bucket: impl Into<String>, store: Arc<dyn ObjectStore>) -> Self {
        Self {
            bucket: bucket.into(),
            store,
        }
    }

    /// Builds an S3-compatible reader from standard AWS environment variables.
    pub fn s3_from_env(bucket: impl Into<String>) -> Result<Self> {
        let bucket = bucket.into();
        let store = AmazonS3Builder::from_env()
            .with_bucket_name(&bucket)
            .build()
            .map_err(|error| PrismError::Storage(error.to_string()))?;
        Ok(Self::new(bucket, Arc::new(store)))
    }
}

#[async_trait]
impl ObjectReader for ObjectStoreReader {
    async fn read_range(
        &self,
        _context: &RequestContext,
        object: &ObjectRef,
        range: ByteRange,
    ) -> Result<Bytes> {
        if object.bucket != self.bucket {
            return Err(PrismError::Storage(format!(
                "object bucket {} does not match configured bucket {}",
                object.bucket, self.bucket
            )));
        }

        self.store
            .get_range(&Path::from(object.key.as_str()), range.start..range.end)
            .await
            .map_err(|error| PrismError::Storage(error.to_string()))
    }
}

/// In-memory reader for unit tests and early vertical slices.
#[derive(Debug, Default)]
pub struct MemoryObjectReader {
    objects: DashMap<(String, String), Bytes>,
}

impl MemoryObjectReader {
    /// Inserts or replaces one complete object.
    pub fn insert(&self, bucket: impl Into<String>, key: impl Into<String>, bytes: Bytes) {
        self.objects.insert((bucket.into(), key.into()), bytes);
    }
}

#[async_trait]
impl ObjectReader for MemoryObjectReader {
    async fn read_range(
        &self,
        _context: &RequestContext,
        object: &ObjectRef,
        range: ByteRange,
    ) -> Result<Bytes> {
        let bytes = self
            .objects
            .get(&(object.bucket.clone(), object.key.clone()))
            .ok_or_else(|| PrismError::Storage(format!("object not found: {}", object.key)))?;
        let start =
            usize::try_from(range.start).map_err(|error| PrismError::Storage(error.to_string()))?;
        let end =
            usize::try_from(range.end).map_err(|error| PrismError::Storage(error.to_string()))?;
        bytes
            .get(start..end)
            .map(Bytes::copy_from_slice)
            .ok_or_else(|| PrismError::Storage("requested range exceeds object length".to_owned()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn memory_reader_returns_exact_ranges() {
        let reader = MemoryObjectReader::default();
        reader.insert("bucket", "object", Bytes::from_static(b"prismfs"));
        let object = ObjectRef {
            bucket: "bucket".to_owned(),
            key: "object".to_owned(),
            size: 7,
            version: None,
        };

        let bytes = reader
            .read_range(
                &RequestContext::new("tenant", "user"),
                &object,
                ByteRange::new(0, 5).expect("valid range"),
            )
            .await
            .expect("read fixture object");

        assert_eq!(bytes, Bytes::from_static(b"prism"));
    }
}
