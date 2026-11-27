# Release SBOMs and signatures

Cosign verification applies to the ZIP and SBOM attached to a GitHub Release.
Composer installs Packagist distributions independently.

## Assets and scope

Each release provides `tracewick-psr-15-<version>.zip`, a CycloneDX 1.6
`.sbom.json`, `checksums.txt` covering both files, and its Cosign bundle
`checksums.txt.sigstore.json`. The ZIP comes from the exact tag's Git export.

The SBOM inventories the package files, hashes, license and source commit.
Composer `require` and `suggest` properties record declared constraints. The
consuming application's lockfile records the versions it resolves.

## Verify a downloaded release

Install Cosign 3 (CI uses 3.1.4) and use a new directory. Set `VER` to the version
of an existing signed release.

```bash
VER=1.0.0-rc.1
PREFIX="tracewick-psr-15-$VER"
gh release download "v$VER" --repo tracewick/psr-15 \
  --pattern "$PREFIX.zip" --pattern "$PREFIX.sbom.json" \
  --pattern checksums.txt --pattern checksums.txt.sigstore.json || exit 1
cosign verify-blob \
  --certificate-identity 'https://github.com/tracewick/psr-15/.github/workflows/release.yml@refs/heads/main' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com' \
  --bundle checksums.txt.sigstore.json checksums.txt || exit 1
sha256sum --check checksums.txt || exit 1
```

On macOS use `shasum -a 256 --check checksums.txt`. Stop on any failure before
extracting or running package code.

This authenticates files produced by the trusted release workflow and detects
changes to the archive or SBOM. Cosign uses GitHub OIDC and an ephemeral key.
The workflow runs on `refs/heads/main` while building from the exact release tag.
Use the attached release ZIP when checking its checksum.

## Maintainer procedure

Release-please still creates the version, changelog, tag and GitHub Release.
Only the main publishing job gets `id-token: write`; it checks the tag/main
ancestry, builds, signs, uploads, then downloads and verifies the assets.
The unsigned CI job checks packaging and SBOM generation without publishing.

If asset publication fails after the tag exists, recover with the Release workflow
on **main**. Set `publish_tag` to the existing tag. The tagged source must contain
this tooling. Recovery replaces the attached assets with `--clobber`.
