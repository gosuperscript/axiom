# Domain context

## Persistence and compilation

- A **Source** is a data-only node in a persistable program description. It may contain other sources and scalar/domain data, but no live service collaborators.
- A **source compiler** is a compile-time adapter owned by an `Extension`. Its map entry associates one exact host `Source` class with the callback that returns its `CompiledNode`.
- **SourceCompilation** is the narrow recursive capability passed to a source compiler. It compiles child sources in the current type environment; it is not a registry or a host service container.
- A **Program** is the ephemeral compiled artifact. Its evaluation closures may capture live services from extensions, so programs are executed and sources are persisted.

Use “source compiler” for this seam. Do not call it a resolver: runtime source resolution and its delegating registry were removed by the compilation pivot.
