"""HTTPS command delivery; an acknowledged result is retried without applying twice."""
import asyncio


class CommandConsumer:
    def __init__(self, client, session_id, apply):
        self.client = client
        self.session_id = session_id
        self.apply = apply
        self.pending = {}
        self.acknowledging = None

    async def request(self, path, body=None):
        return await asyncio.to_thread(self.client.request, path, body)

    async def poll_once(self):
        for command_id, result in list(self.pending.items()):
            await self.request(f'/api/v1/commands/{command_id}/result', result)
            del self.pending[command_id]

        # Retain the command across an ambiguous ACK timeout. ACK is idempotent.
        commands = [self.acknowledging] if self.acknowledging else (
            await self.request(f'/api/v1/sessions/{self.session_id}/commands'))['data']
        for command in commands:
            self.acknowledging = command
            command_id = command['id']
            ack = await self.request(f'/api/v1/commands/{command_id}/ack', {})
            self.acknowledging = None
            if ack['data']['status'] != 'acked':
                continue
            try:
                if command['type'] != 'apply':
                    raise ValueError('This Omniverse extension does not support that command.')
                result = await self.apply(command['payload'])
                outcome = {'status': 'done', 'result': result, 'message': result['message']}
            except asyncio.CancelledError:
                # A shutdown must never schedule further scene edits.
                raise
            except Exception as exc:
                outcome = {'status': 'failed', 'message': str(exc)[:2000]}
            self.pending[command_id] = outcome
            await self.request(f'/api/v1/commands/{command_id}/result', outcome)
            del self.pending[command_id]
