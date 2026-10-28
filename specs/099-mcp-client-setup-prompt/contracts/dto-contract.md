# Contract — client DTO producer

`public/Discovery/ConnectionMethodRegistry::get_clients( ?array $server = null )` is the single DTO
producer consumed by the PHP admin renderer, the React wizard, and companion plugins. `D43` governs
it.

## Change

One additive field:

```
'setupPrompt' => string   // present ONLY when $server !== null
```

## Binding rules (from D43)

| Rule | Application here |
|---|---|
| Encoding decisions live in the producer, never per-renderer | The prompt is assembled here, so both surfaces receive one finished string |
| New fields additive; never rename or remove | `setupPrompt` is new; nothing else changes |
| Server-scoped enrichment opt-in by param | Gated on `$server !== null`, matching `config` |
| Translation keys identical across renderers | Guaranteed because the prose is translated **once**, here |

## Consumer obligations

- **The React surface MUST consume `setupPrompt` and MUST NOT reassemble the prompt in JavaScript.**
  Rebuilding it would key the same sentences a second time (breaking D43's translation rule) and
  break FR-003's byte-identity. This is the single most important consumer rule in this feature.
- Consumers escape at the render boundary; DTO strings are deliberately unescaped (SEC-035-002).
- A consumer that does not know `setupPrompt` MUST be unaffected by its presence.

## Known hazard this contract does not prevent

Both renderers already read this DTO and drifted anyway, because `Step11_ClientDetail` **ignores**
`meta.config_files`, which the DTO has always carried. Sharing a producer is necessary but not
sufficient — a renderer can silently ignore a field. FR-013 fixes the instance; reviewers should
check new DTO fields are actually read by every consumer, not merely published.
