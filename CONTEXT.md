# Domain context

## Persistence and compilation

- A **Source** is a data-only node in a persistable program description. It may contain other sources and scalar/domain data, but no live service collaborators.
- A **source compiler** is a compile-time adapter owning one exact `Source` class: the callback that returns the class's `CompiledSource`. Host extensions register theirs through `Extension::sourceCompilers()`; the core language's nodes are registered by `Dialect::core()` (`CoreSourceCompilers`) through the same map, so every node compiles through one registered rule and claiming an already-owned class is the same loud error either way.
- A **CompiledSource** couples one certified return type to a composable evaluation. Its ordinary operations make present-value mapping, absence-aware mapping, child composition, and bound-operation application available without exposing `CompiledNode`, `Runtime`, or the internal `Result<Option<...>>` channel.
- **SourceCompilation** is the straight-line capability passed to a source compiler. It compiles child sources in the current type environment, resolves symbols, binds typed infix and prefix operations from the composed dialect, types embedded PHP values literal-first, and constructs `CompiledSource` values. Nested refusals automatically return through `Expression::compile()`; source compiler callbacks do not compose compilation `Result` values themselves.
- A **Program** is the ephemeral compiled artifact. Its evaluation closures may capture live services from extensions, so programs are executed and sources are persisted.
- An **execution observer** belongs to one `Program` invocation. Core emits an ordered lifecycle for every compiled source node; tracing and telemetry packages interpret those events. Observers are never stored on sources, expressions, or programs.

Use “source compiler” for this seam. Do not call it a resolver: runtime source resolution and its delegating registry were removed by the compilation pivot.
