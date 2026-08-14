<?php

declare(strict_types=1);

namespace Superscript\Axiom;

/**
 * @template-covariant T = mixed
 *
 * A node in a program description. Host-defined sources keep only the data
 * needed to describe the operation so the tree can be persisted and loaded
 * later; live collaborators belong to the source compiler registered by an
 * Extension, and are captured only in the compiled Program.
 */
interface Source
{
    /**
     * Every source nested in this node's persisted description.
     *
     * @return iterable<Source>
     */
    public function children(): iterable;
}
