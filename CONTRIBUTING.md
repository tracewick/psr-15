# Contributing

Use Conventional Commits and open feature pull requests into `develop`.

Before opening a PR, install dependencies and run `composer ci`. Run
`composer test:integration` with Excimer loaded. CI also runs Codecov,
Infection and the worker smoke tests documented in the [README](README.md).
For release changes, run the package tests against a Git snapshot:

```sh
python3 -m unittest discover -s scripts -p 'test_*.py' -v
```

## Releasing

The maintainer branches `release/<version>` from `develop` and opens a preparation
PR into `main`. Use a Conventional Commit with a `Release-As` footer on the
release branch. For the first candidate:

```text
chore: prepare 1.0.0-rc.1

Release-As: 1.0.0-rc.1
```

1. Merge the preparation PR with a merge commit to preserve the footer.
2. Review the release-please PR and confirm its version and prerelease status.
   Merging it generates the tag, changelog and GitHub Release.
3. Verify the [signed assets and SBOM](docs/verify-release.md). Register the
   package on Packagist at first publication and enable its GitHub webhook.
   Run the README installation command in a clean project and verify with
   Excimer that an HTTP request reaches Pyroscope via the spool reader.
4. Merge the generated back-merge PR from `main` into `develop` with a merge commit.

Grant the `tracewick-release` GitHub App access to this repository and include it
in the protected tag bypass list. Set the `RELEASE_APP_ID` Actions variable and
`RELEASE_APP_PRIVATE_KEY` Actions secret. The App needs contents and pull
request write permissions. Release-please maintains the version manifest,
changelog and tags.

Contributions are licensed under [Apache-2.0](LICENSE).
