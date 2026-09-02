use std::sync::Arc;

use bytes::Bytes;
use object_store::{ObjectStoreExt, aws::AmazonS3Builder, path::Path};
use prismfs_core::{ByteRange, ObjectRef, RequestContext};
use prismfs_storage::{ObjectReader, ObjectStoreReader};

#[tokio::test]
#[ignore = "requires the local RustFS fixture"]
async fn reads_an_exact_range_from_s3_compatible_storage() {
    let bucket = std::env::var("PRISMFS_S3_BUCKET").expect("PRISMFS_S3_BUCKET must be set");
    let key = Path::from("integration/prismfs-storage.txt");
    let store = Arc::new(
        AmazonS3Builder::from_env()
            .with_bucket_name(&bucket)
            .build()
            .expect("build S3-compatible client"),
    );

    store
        .put(&key, Bytes::from_static(b"0123456789").into())
        .await
        .expect("write integration fixture");

    let reader = ObjectStoreReader::new(&bucket, store.clone());
    let object = ObjectRef {
        bucket,
        key: key.to_string(),
        size: 10,
        version: None,
    };
    let bytes = reader
        .read_range(
            &RequestContext::new("integration", "cargo-test"),
            &object,
            ByteRange::new(2, 6).expect("valid byte range"),
        )
        .await
        .expect("read fixture range");

    store
        .delete(&key)
        .await
        .expect("delete integration fixture");
    assert_eq!(bytes, Bytes::from_static(b"2345"));
}
