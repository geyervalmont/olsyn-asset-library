//! Access events queued for delivery to the control plane.
//!
//! Recording stays synchronous and cheap: an event is filtered by operation,
//! serialised into its wire form, and pushed onto a bounded queue. A shipper
//! (in the server crate) drains the queue in batches and posts them.

use std::{
    collections::{BTreeSet, VecDeque},
    sync::{
        Arc, Mutex, OnceLock,
        atomic::{AtomicU64, Ordering},
    },
    time::SystemTime,
};

use serde::{Deserialize, Serialize};

use crate::AuditEvent;

/// Operations shipped when nothing else is configured.
pub const DEFAULT_OPERATIONS: &[&str] = &["read", "open"];

/// Queue capacity when nothing else is configured.
pub const DEFAULT_CAPACITY: usize = 10_000;

/// One access event in the wire format the control plane accepts.
#[derive(Clone, Debug, Deserialize, PartialEq, Serialize)]
pub struct AccessEvent {
    /// Correlation identifier of the filesystem request.
    pub request_id: String,
    /// When the access completed, RFC 3339 in UTC.
    pub occurred_at: String,
    /// Principal as PrismFS saw it, for example `uid:1000`.
    pub principal: String,
    /// Filesystem operation.
    pub operation: String,
    /// Virtual path.
    pub path: String,
    /// `allow`, `deny`, or `error`.
    pub result: String,
    /// Bytes returned, for reads.
    pub bytes: Option<u64>,
    /// Operation duration in milliseconds.
    pub duration_ms: f64,
}

impl AccessEvent {
    /// Captures an audit event at the current time.
    #[must_use]
    pub fn now(event: &AuditEvent<'_>) -> Self {
        Self {
            request_id: event.context.request_id.to_string(),
            occurred_at: humantime::format_rfc3339_millis(SystemTime::now()).to_string(),
            principal: event.context.principal_id.clone(),
            operation: event.operation.to_owned(),
            path: event.path.to_string(),
            result: event.result.to_owned(),
            bytes: event.bytes,
            duration_ms: event.duration_ms,
        }
    }
}

/// The body posted to the control plane.
#[derive(Clone, Debug, Deserialize, PartialEq, Serialize)]
pub struct AccessBatch {
    /// Events in the order they happened.
    pub events: Vec<AccessEvent>,
}

/// A bounded, operation-filtered queue of access events.
#[derive(Debug)]
pub struct AuditQueue {
    events: Mutex<VecDeque<AccessEvent>>,
    capacity: usize,
    operations: BTreeSet<String>,
    dropped: AtomicU64,
}

impl AuditQueue {
    /// Creates a queue that keeps at most `capacity` events for the given
    /// operations, dropping the oldest when full.
    #[must_use]
    pub fn new(capacity: usize, operations: impl IntoIterator<Item = String>) -> Self {
        Self {
            events: Mutex::new(VecDeque::new()),
            capacity: capacity.max(1),
            operations: operations.into_iter().collect(),
            dropped: AtomicU64::new(0),
        }
    }

    /// Whether events for this operation are kept at all.
    #[must_use]
    pub fn accepts(&self, operation: &str) -> bool {
        self.operations.contains(operation)
    }

    /// Queues an event, evicting the oldest when the queue is full. Returns
    /// whether the event was kept.
    pub fn push(&self, event: AccessEvent) -> bool {
        if !self.accepts(&event.operation) {
            return false;
        }
        let mut events = self.lock();
        if events.len() >= self.capacity {
            events.pop_front();
            self.dropped.fetch_add(1, Ordering::Relaxed);
        }
        events.push_back(event);
        true
    }

    /// Removes up to `max` of the oldest events.
    pub fn drain(&self, max: usize) -> Vec<AccessEvent> {
        let mut events = self.lock();
        let count = max.min(events.len());
        events.drain(..count).collect()
    }

    /// Puts a failed batch back at the front, oldest first, still bounded.
    pub fn requeue(&self, batch: Vec<AccessEvent>) {
        let mut events = self.lock();
        for event in batch.into_iter().rev() {
            events.push_front(event);
        }
        while events.len() > self.capacity {
            events.pop_back();
            self.dropped.fetch_add(1, Ordering::Relaxed);
        }
    }

    /// Events waiting to be shipped.
    #[must_use]
    pub fn len(&self) -> usize {
        self.lock().len()
    }

    /// Whether nothing is waiting.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// Events evicted because the queue was full.
    #[must_use]
    pub fn dropped(&self) -> u64 {
        self.dropped.load(Ordering::Relaxed)
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, VecDeque<AccessEvent>> {
        self.events
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner)
    }
}

static QUEUE: OnceLock<Arc<AuditQueue>> = OnceLock::new();

/// Installs the process-wide queue that [`crate::record`] feeds. Only the
/// first installation takes effect.
pub fn install(queue: Arc<AuditQueue>) -> Result<(), Arc<AuditQueue>> {
    QUEUE.set(queue)
}

/// The installed queue, if shipping is enabled.
#[must_use]
pub fn installed() -> Option<&'static Arc<AuditQueue>> {
    QUEUE.get()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn event(operation: &str, path: &str) -> AccessEvent {
        AccessEvent {
            request_id: "r".into(),
            occurred_at: "2026-09-03T00:00:00.000Z".into(),
            principal: "uid:1000".into(),
            operation: operation.into(),
            path: path.into(),
            result: "allow".into(),
            bytes: Some(1),
            duration_ms: 0.1,
        }
    }

    fn queue(capacity: usize) -> AuditQueue {
        AuditQueue::new(capacity, DEFAULT_OPERATIONS.iter().map(|s| (*s).to_owned()))
    }

    #[test]
    fn filters_noise_and_keeps_reads_and_opens() {
        let queue = queue(10);
        assert!(!queue.push(event("getattr", "/a")));
        assert!(!queue.push(event("readdir", "/a")));
        assert!(queue.push(event("open", "/a")));
        assert!(queue.push(event("read", "/a")));
        assert_eq!(queue.len(), 2);
    }

    #[test]
    fn drops_the_oldest_when_full() {
        let queue = queue(2);
        queue.push(event("read", "/1"));
        queue.push(event("read", "/2"));
        queue.push(event("read", "/3"));
        let drained = queue.drain(10);
        assert_eq!(
            drained.iter().map(|e| e.path.as_str()).collect::<Vec<_>>(),
            ["/2", "/3"]
        );
        assert_eq!(queue.dropped(), 1);
        assert!(queue.is_empty());
    }

    #[test]
    fn requeued_batches_keep_their_order_ahead_of_newer_events() {
        let queue = queue(10);
        queue.push(event("read", "/1"));
        queue.push(event("read", "/2"));
        let failed = queue.drain(2);
        queue.push(event("read", "/3"));
        queue.requeue(failed);
        assert_eq!(
            queue
                .drain(10)
                .iter()
                .map(|e| e.path.as_str())
                .collect::<Vec<_>>(),
            ["/1", "/2", "/3"]
        );
    }

    #[test]
    fn batches_serialise_to_the_wire_contract() {
        let json = serde_json::to_string(&AccessBatch {
            events: vec![event("read", "/materials/x.png")],
        })
        .expect("serialises");
        assert!(json.starts_with(r#"{"events":[{"request_id":"r","occurred_at":"2026-09-03T00:00:00.000Z","principal":"uid:1000","operation":"read","path":"/materials/x.png","result":"allow","bytes":1,"duration_ms":0.1}]}"#));
    }
}
