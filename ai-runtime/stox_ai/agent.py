from __future__ import annotations

import asyncio
import json
import os
from uuid import uuid4

import httpx
from fastmcp import Client, FastMCP
from .agent_contracts import INPUTS, READ_TOOLS, InvestigationRequest, PlannerResult, Projection, Synthesis, MutationPreview
from .schemas import InferenceRequest


class AgentFailure(Exception):
    pass


class Gateway:
    """Only the single configured Laravel gateway is reachable by domain tools."""
    def __init__(self, request: InvestigationRequest):
        self.request = request

    async def call(self, operation, **payload):
        base = os.getenv('STOX_LARAVEL_INTERNAL_URL', '').rstrip('/')
        key = os.getenv('STOX_AI_SERVICE_KEY', '')
        if not base or not key:
            raise AgentFailure('gateway_unavailable')
        async with httpx.AsyncClient(timeout=10, follow_redirects=False) as client:
            response = await client.post(base + '/api/internal/v1/ai-tools/call',
                headers={'X-StoX-AI-Service-Key': key}, json={
                    'run_id': self.request.run_id, 'delegation': self.request.delegation,
                    'operation': operation, **payload})
            if len(response.content) > 256000:
                raise AgentFailure('response_size_limit')
            response.raise_for_status()
            envelope = response.json()
            if envelope.get('success') is not True:
                raise AgentFailure('gateway_failed')
            return envelope['data']


def tool_server(gateway: Gateway, allowed: set[str]) -> FastMCP:
    """Request-local in-process MCP server. No network MCP listener or external MCP client."""
    server = FastMCP('StoX governed tools')
    for tool in sorted(allowed & READ_TOOLS):
        def wrapper(name, model):
            async def invoke(arguments):
                return Projection.model_validate(await gateway.call('read', tool=name,
                    arguments=model.model_validate(arguments).model_dump(exclude_none=True)))
            invoke.__annotations__ = {'arguments': model, 'return': Projection}
            return invoke
        server.tool(wrapper(tool, INPUTS[tool]), name=tool, description=f'Read authorized StoX {tool}. Missing evidence has explicit availability.', annotations={'readOnlyHint': True}, timeout=10)
    for tool in sorted(allowed - READ_TOOLS):
        if tool not in INPUTS:
            continue
        def proposal(name, model):
            async def propose(arguments):
                arguments = model.model_validate(arguments).model_dump(exclude_none=True)
                result = await gateway.call('preview', plan=[{'tool': name, 'arguments': arguments, 'reason': 'Requested ' + name.replace('.', ' ')}])
                return MutationPreview.model_validate(result)
            propose.__annotations__ = {'arguments': model, 'return': MutationPreview}
            return propose
        server.tool(proposal(tool, INPUTS[tool]), name=tool,
            description='Prepare a deterministic grouped approval preview. Does not execute business mutations; only explicit user approval in Laravel can execute this plan.',
            annotations={'readOnlyHint': False, 'destructiveHint': tool in {'watchlist.delete', 'watchlist.remove_stock'}}, timeout=10)
    return server


async def investigate(request: InvestigationRequest, infer, gateway=None):
    gateway = gateway or Gateway(request)
    async with asyncio.timeout(55):
        catalog = await gateway.call('catalog')
        allowed = {item['id'] for item in catalog['tools']} & set(INPUTS)
        evidence = []
        seen = set()
        calls = 0
        server = tool_server(gateway, allowed)
        async def model(capability, data, contract):
            result = await infer(InferenceRequest(request_id=str(uuid4()), capability_id=capability,
                calling_feature='V9-AI-002', context={'user_id': request.user_id},
                input=data, user_prompt=json.dumps(data), output_schema=contract.model_json_schema()))
            if result.status != 'success' or result.structured is None:
                raise AgentFailure(result.error_code or 'inference_failed')
            return contract.model_validate(result.structured)
        async with Client(server) as client:
            for iteration in range(3):
                plan = await model('agent_planning', {'objective': request.objective, 'evidence': evidence,
                    'tools': [{**item, 'input_schema': INPUTS[item['id']].model_json_schema()} for item in catalog['tools'] if item['id'] in allowed],
                    'instructions': 'Use the minimum relevant read tools. Read current state before actions. Never calculate portfolio metrics yourself. Never propose broker actions. Actions require a separate Laravel preview and explicit user approval. Do not output chain of thought.'}, PlannerResult)
                for call in [*plan.read_tools, *plan.actions]:
                    if call.tool not in allowed:
                        raise AgentFailure('tool_not_allowed')
                if plan.actions:
                    if not evidence:
                        raise AgentFailure('current_state_required')
                    return await gateway.call('preview', plan=[call.model_dump() for call in plan.actions])
                for call in plan.read_tools:
                    identity = json.dumps([call.tool, call.arguments.model_dump(exclude_none=True)], sort_keys=True)
                    if identity in seen:
                        continue
                    if calls >= 8:
                        raise AgentFailure('max_steps_exceeded')
                    seen.add(identity)
                    calls += 1
                    try:
                        async with asyncio.timeout(10):
                            response = await client.call_tool(call.tool, {'arguments': call.arguments.model_dump(exclude_none=True)})
                        projection = Projection.model_validate(response.structured_content)
                        evidence.append({'tool': call.tool, 'result': projection.model_dump()})
                    except Exception as error:
                        # Expose only an operational category; raw transport/model errors stay private.
                        evidence.append({'tool': call.tool, 'result': {'availability': 'unavailable', 'reason': 'tool_timeout' if isinstance(error, TimeoutError) else 'tool_failed'}})
                if plan.ready or not plan.read_tools:
                    break
        if not evidence:
            answer = 'No relevant account evidence was obtained. Broker trading is unavailable.'
        else:
            synthesis = await model('agent_synthesis', {'objective': request.objective, 'evidence': evidence,
                'instructions': 'Interpret only supplied evidence. Laravel owns calculations. Explicitly disclose unavailable/incomplete/not_supported evidence. Never claim that mutations were executed. No private chain of thought.'}, Synthesis)
            def unavailable(value, path):
                if isinstance(value, dict):
                    result = [path] if value.get('availability') in {'not_initialized', 'unavailable', 'incomplete', 'not_supported'} else []
                    for key, item in value.items():
                        result.extend(unavailable(item, path + '.' + key))
                    return result
                if isinstance(value, list):
                    return [item for i, entry in enumerate(value) for item in unavailable(entry, path + '.' + str(i))]
                return []
            missing = [path for e in evidence for path in unavailable(e['result'], e['tool'])]
            answer = synthesis.answer
            disclosures = list(dict.fromkeys([*missing, *synthesis.missing_evidence]))
            if disclosures:
                answer += '\n\nUnavailable or incomplete evidence: ' + ', '.join(disclosures)
        return await gateway.call('complete', answer=answer[:6000])
