# Release qualification and publication checklist

Target: **2.0.0**. This is an already-published Composer project, with a new major
runtime requirement. Preparation, local tests, owner merge, exact-SHA qualification,
publication, support and deployment are distinct facts. There is no authorization
to tag, publish, deploy or execute production SQL from this checklist alone.

## Before the release integration is merged

- Allocate represented changes to undated CHANGELOG2.0.0, leaving Unreleased for
  genuinely unallocated changes. Do not invent a2.0.0 release/tag link or date.
- Keep the exact artifact identity2.0.0 and time-scope publication/support snapshots.
  Use current Composer PHP/license truth and actual live registry badges.
- Review README additions without discarding existing contracts/examples; verify
  Maatify logo/badges/author/license/footer, link targets and rendered layout.
- Verify upgrade order, consumer bindings, receipt/key retention, optional synthetic
  storage/retention/cleanup and cache/worker restart instructions.
- Exercise the actual consumer shell/PHP examples on an isolated test service;
  no live SMTP or production data. Remove complete credential values from examples.
- Review community/security policy and preserve approved1.x support; preparation
  does not claim2.x already supported. Confirm additional channels remain planned.
- Keep project lock; review metadata/platform changes and do not change dependency
  versions as an incidental release-doc action.

## Qualify one clean final main SHA

After owner-approved merge, refresh main in a clean checkout and capture exact SHA.
All checks below must use that same SHA; a later commit invalidates its qualification.
Normal contributor verification needs the full source clone, not the deployment archive.

1. Pass full applicable CI: PHP8.4/8.5 validation, locked install, platform/audit,
   required real HTTP/MySQL/Redis PHPUnit, max PHPStan, PHP lint and artifact check.
   A job with zero executed steps (for example Billing) is not PASS.
2. Check the rendered README and external publication/asset links. Record failures,
   not a presumed PASS based solely on Markdown syntax.
3. Capture exact source/ZIP identity; example commands in the clean checkout:

   ```bash
   git rev-parse HEAD
   git status --porcelain
   git archive --format=zip --output=/tmp/channel-delivery-2.0.0.zip HEAD
   python3 tools/check_release_artifact.py --target 2.0.0 \
     --archive /tmp/channel-delivery-2.0.0.zip --source . --commit <EXACT_MAIN_SHA>
   python3 tools/check_release_artifact.py --target 2.0.0 \
     --archive /tmp/channel-delivery-2.0.0.zip --self-test
   ```

   Replace the SHA placeholder with the captured40-character value. The checker
   rejects a dirty/different source when --commit is supplied and compares actual
   ZIP bytes to source. Without --commit it is only provisional snapshot checking,
   not release-SHA qualification. Tests/CI/runtime secrets/cache/vendor are excluded
   by .gitattributes; docs, examples, .env.example, .gitignore, license and runtime remain.
4. Record SHA, tree, archiveSHA256, CI run/jobs and artifact-check report externally.
   No generated local evidence or secrets are added to the release tree.

## Owner-authorized publication only

- Tag and GitHub Release must target the already-qualified exact SHA. Do not reuse
  checks from the earlier feature merge or tag a later unqualified commit.
- Curate release notes from the [2.0.0 guide](2.0.0.md), with compatibility and upgrade
  links; generated PR notes are supplemental. Preserve immutable tag identity.
- Wait for registry resolution and install exact version in a clean external root:

  ```bash
  composer create-project --prefer-dist --no-dev --no-scripts --no-interaction \
    maatify/channel-delivery /tmp/channel-delivery-2.0.0-install 2.0.0
  ```

  Use a new empty destination. Verify actual resolved version/dist reference against
  the tag, and compare distributed tracked file content/required files to the same
  source. Do not run HTTP/worker/migrations as an incidental installation check.
- Verify the actual Composer-distributed ZIP through the artifact checker and keep
  exact-version/content evidence. Successful installation alone is not delivery or
  deployment acceptance. Do not move a tag to repair a published artifact; use a
  new version for tracked corrections.
- Independently reconcile live default-branch publication facts, actual CHANGELOG
  date/link and2.x security support after publication;1.x is not withdrawn. This
  does not mutate the old release artifact or postpone freshness on an integrity error.
- Close the governing release issue only after its required evidence/reconciliations.
  Deployments and production schemas/configuration remain separately authorized.
