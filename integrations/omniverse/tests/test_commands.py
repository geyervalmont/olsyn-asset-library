import asyncio
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'exts' / 'olsyn.opal'))
from olsyn.opal.commands import CommandConsumer
from olsyn.opal.browser import page_numbers, preview_name


class FakeClient:
    def __init__(self):
        self.command = {'id': 1, 'type': 'apply', 'payload': {'variant_uuid': 'example'}}
        self.status = 'queued'
        self.fail_ack = False
        self.fail_result = False
        self.result = None

    def request(self, path, body=None):
        if path.endswith('/ack'):
            self.status = 'acked'
            if self.fail_ack:
                self.fail_ack = False
                raise OSError('Response lost after ACK')
            return {'data': {'status': self.status}}
        if path.endswith('/result'):
            if self.fail_result:
                self.fail_result = False
                raise OSError('Result connection lost')
            self.result = body
            self.status = body['status']
            return {'data': {}}
        return {'data': [self.command] if self.status == 'queued' else []}


class CommandsTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.client = FakeClient()
        self.applied = []
        async def apply(payload):
            self.applied.append(payload)
            return {'message': 'Applied', 'count': 1}
        self.consumer = CommandConsumer(self.client, 123, apply)

    async def test_lost_ack_recovers_without_losing_command(self):
        self.client.fail_ack = True
        with self.assertRaises(OSError):
            await self.consumer.poll_once()
        self.assertEqual(self.applied, [])
        await self.consumer.poll_once()
        self.assertEqual(len(self.applied), 1)
        self.assertEqual(self.client.result['status'], 'done')

    async def test_lost_result_is_retried_without_reapplying(self):
        self.client.fail_result = True
        with self.assertRaises(OSError):
            await self.consumer.poll_once()
        await self.consumer.poll_once()
        self.assertEqual(len(self.applied), 1)
        self.assertEqual(self.client.result['status'], 'done')

    async def test_apply_failure_is_reported_to_website(self):
        async def fail(_):
            raise ValueError('Select geometry first')
        self.consumer.apply = fail
        await self.consumer.poll_once()
        self.assertEqual(self.client.result, {'status': 'failed', 'message': 'Select geometry first'})

    async def test_unknown_command_never_edits_scene(self):
        self.client.command['type'] = 'sync'
        await self.consumer.poll_once()
        self.assertEqual(self.applied, [])
        self.assertEqual(self.client.result['status'], 'failed')

    async def test_cancellation_does_not_report_success(self):
        async def cancel(_):
            raise asyncio.CancelledError()
        self.consumer.apply = cancel
        with self.assertRaises(asyncio.CancelledError):
            await self.consumer.poll_once()
        self.assertIsNone(self.client.result)


class BrowserTest(unittest.TestCase):
    def test_bounded_pagination_keeps_first_last_and_neighbors(self):
        self.assertEqual(page_numbers(1, 1), [1])
        self.assertEqual(page_numbers(50, 100), [1, None, 48, 49, 50, 51, 52, None, 100])
        self.assertEqual(page_numbers(100, 100), [1, None, 98, 99, 100])

    def test_previews_are_versioned_and_cannot_escape_cache(self):
        item = {'uuid': '0195bd23-9123-7000-8000-000000000001', 'material_version': 1}
        self.assertNotEqual(preview_name(item), preview_name(dict(item, material_version=2)))
        with self.assertRaises(ValueError):
            preview_name(dict(item, uuid='../bad'))
