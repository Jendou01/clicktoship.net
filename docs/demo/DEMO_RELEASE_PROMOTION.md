# Manual demo release promotion

Separate from unchanged Phase 17 staging/production promotion. No auto-deploy on push. This is reviewed content integrity, **not cryptographic build provenance** or reproducible-build certification.

1. Develop on a feature branch, run tests and open a PR. Required independent reviewers inspect this profile, source, migrations, dependency locks, Dockerfiles and accepted synthetic-only loss policy. Merge only after review/CI; operator cannot self-declare that review occurred.
2. Freeze the full 40-character approved merged SHA. Require completed successful **Foundation CI** for that exact SHA. Do not reuse CI for the old Phase 17 SHA after code changes. Build on a trusted clean isolated packaging machine, never with credentials/data in the context.
3. Package locally (no push/deploy):

```sh
git checkout --detach APPROVED_40_CHARACTER_SHA
python3 scripts/demo/package.py --sha APPROVED_40_CHARACTER_SHA \
  --ci-run-id EXACT_SUCCESSFUL_FOUNDATION_RUN_ID \
  --reviewers REVIEWER_ONE REVIEWER_TWO --output /protected/new-demo-release
```

API and gateway reuse Phase 17 Dockerfiles. The small demo web build uses the same pinned dependency/runtime recipe with a build-time TEST/DEMO banner and a demo profile label. Mutable build tags are export handles only, never deployment authority. Output is Docker's securely transferred image archive, exact image content digests, source SHA, archive SHA-256, CI ID and review identities. It is not an OCI registry manifest; the demo explicitly supports Docker save/load archives rather than fabricating registry digests.

4. Two independent reviewers verify the candidate against their approved build/CI record, archive checksum and image content IDs/revision labels. A designated independent release reviewer signs the **exact manifest bytes** with an organization/owner-approved review key kept off the VPS and out of ChatGPT. Do not let packaging automatically sign its own approval.

```sh
openssl dgst -sha256 -sign /independent-custody/release-review.key \
  -out /protected/new-demo-release/manifest.sig /protected/new-demo-release/manifest.json
sha256sum /independent-custody/release-review.pub
```

An encrypted private review key prompts locally for its passphrase. Never put it in argv, an environment variable, the image archive, chat or the VPS. The signature authenticates reviewer approval only; it is **not signed build provenance**. Pin the public-key file SHA-256 through an independent owner-approved channel; a public key supplied alongside an archive without independent pinning is not trusted. Reviewer names in JSON alone prove no review; signature custody and the protected PR approval record supply that authority. Do not claim two cryptographic signers from one detached approval signature.

5. Transfer archive/manifest/signature/public key through the existing authenticated private transfer mechanism; these files contain no runtime credentials. Do not publish images or source artifacts publicly beyond existing source access. On the exact clean approved VPS checkout, use `control.py load` with the independent pinned public key. It verifies signature, SHA, exact successful hosted CI, archive SHA before load, and actual loaded immutable IDs/revision labels. Fixed support images are pinned separately in Compose. The operator must inspect trust/dependency risks; matching labels alone are insufficient.

6. Generate fresh runtime secrets and verify/start as REMOTE_DEMO_SETUP describes. No `--local-image-ids` exception or change to production preflight exists. Manual owner deployment authorization for exact SHA/host is still required. The old approved Phase 17 images cannot contain this new profile and must not be treated as its release.

7. Later changes repeat feature branch → tests → PR → independent review/merge → exact successful CI → new reviewed package/signature → manual host approval → isolated fresh demo. Do not swap a tag on a running instance. Stop all demo writers/workers/scheduler before upgrade/reset; migrations only use a separate one-shot owner. For a disposable reset, tear down only the verified demo project and create a fresh unique one; retained databases are never migrated or erased. Rebuild synthetic fixtures. Acceptance failure leaves ingress closed. Rollback means a freshly provisioned isolated instance of the last approved code with its own new credentials and disposable data; never downgrade retained schemas or reuse lost keys.
