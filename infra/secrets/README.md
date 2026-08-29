# infra/secrets

SOPS-encrypted secrets only. Age keys are never committed. Nothing in this directory is
readable without a key held outside the repository. See `.sops.yaml` at the repo root and
project-context.md rule 24 — no credential or secret in any file, ever, in plaintext.
