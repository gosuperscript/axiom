# Domain context

## Persistence and compilation

- A **Source** is a data-only node in a persistable program description. It may contain other sources and scalar/domain data, but no live service collaborators.
- A **source compiler** is a compile-time adapter owning one exact `Source` class: the callback that returns the class's `CompiledNode`. Host extensions register theirs through `Extension::sourceCompilers()`; the core language's nodes are registered by `Dialect::core()` (`CoreSourceCompilers`) through the same map, so every node compiles through one registered rule and claiming an already-owned class is the same loud error either way.
- **SourceCompilation** is the capability passed to a source compiler. It compiles child sources in the current type environment, resolves symbols, binds typed infix and prefix operations from the composed dialect, and types embedded PHP values literal-first. It is the compiler's entire face — the core rules consume exactly what host compilers receive — and it is not a registry, runtime dispatcher, or host service container.
- A **Program** is the ephemeral compiled artifact. Its evaluation closures may capture live services from extensions, so programs are executed and sources are persisted.
- An **execution observer** belongs to one `Program` invocation. Core emits an ordered lifecycle for every compiled source node; tracing and telemetry packages interpret those events. Observers are never stored on sources, expressions, or programs.

Use “source compiler” for this seam. Do not call it a resolver: runtime source resolution and its delegating registry were removed by the compilation pivot.
