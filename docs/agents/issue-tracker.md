# Issue tracker: GitHub

Issues and PRDs for this repository live in GitHub Issues under `gosuperscript/axiom`. Use the `gh` CLI for operations.

## Conventions

- Create an issue with `gh issue create` and a body file for multiline content.
- Read an issue and its discussion with `gh issue view <number> --comments`.
- List issues with `gh issue list`, requesting structured JSON when filtering or summarizing.
- Comment with `gh issue comment <number> --body-file <path>`.
- Apply or remove labels with `gh issue edit`.
- Close with `gh issue close` and a concise closing comment.

Infer the repository from the local GitHub remote when working inside this clone.

## Skill routing

When a skill says to publish to the issue tracker, create a GitHub issue in `gosuperscript/axiom`. When it says to fetch the relevant ticket, read the issue and its comments before acting.
