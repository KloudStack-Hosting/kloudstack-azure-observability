# Deploying to the WordPress.org plugin directory

How `kloudstack-azure-observability` is published and updated on WordPress.org.

- **Slug:** `kloudstack-azure-observability` (locked — WordPress.org bans "WordPress" in plugin
  slugs, and the slug cannot be changed after approval)
- **SVN repository:** <https://plugins.svn.wordpress.org/kloudstack-azure-observability/>
- **Public page:** <https://wordpress.org/plugins/kloudstack-azure-observability/>
- **Upstream source of truth:** this Git repository, `main` branch

---

## 1. Two channels, one version number

The plugin reaches users two different ways, and they are not the same pipeline:

| | Public | KloudStack managed stacks |
|---|---|---|
| Distributed by | WordPress.org SVN | GitHub release asset |
| Users get updates from | wp-admin → Plugins → Update | The stack image + MU-plugin push |
| Controlled by | `Stable tag` in `readme.txt` | `observability-version.txt` in the `KloudStack ACR Images` repo |

**Both must be updated for a release, and they can drift.** A version published to SVN but not
pinned in `observability-version.txt` means public users are ahead of our own customers. The
reverse means our customers run a build the public cannot get.

Git tags drive the GitHub release; SVN is a separate manual push. Nothing automatically keeps
them in step — that is what the checklist in §7 is for.

### SVN is a release system, not a development system

WordPress.org states this plainly in [their own
guidance](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/#best-practices):

> Unlike GitHub, SVN is meant to be a *release* system, not a development system.

**All development happens in Git, on `main`.** Nothing is ever written, fixed, or iterated in SVN.
The SVN repository receives finished, tagged, tested releases and nothing else — it is a
distribution channel that happens to be spelled "version control".

Two practical consequences:

- **Never commit a work in progress to trunk.** WordPress.org's own advice is to push once, when
  you are ready to go.
- **Do not drip small commits at it.** Every push to SVN rebuilds the ZIP files for *all* versions
  of the plugin, and that rebuild can delay updates reaching sites by up to six hours.

---

## 2. One-time setup

### Install Subversion

**Only needed for the manual path.** The normal route is the GitHub workflow in §11, which needs
no local SVN at all. Install this if you need to inspect the repository or publish by hand.

- `winget install --id Slik.Subversion` — a CLI-only Subversion build, preferred over TortoiseSVN
  because there is no "command line client tools" checkbox to forget
- Verify with `svn --version`

The installer is an MSI and requires UAC elevation; in a non-elevated or automated shell it fails
with exit code **1602** ("user cancelled"), which is the prompt going unanswered rather than a
broken package. Accept the prompt, or extract the MSI without installing:

```bash
msiexec /a Slik-Subversion-1.14.2-x64.msi /qn TARGETDIR=C:\path\to\extract
# svn.exe then sits at C:\path\to\extract\SlikSvn\bin\svn.exe
```

### Credentials

SVN authenticates with your **WordPress.org account username and password** — the same account
that submitted the plugin. There is no separate SVN password and no SSH key.

SVN caches the credential after the first commit. On a shared machine, pass `--no-auth-cache`.

### Check out the repository

```bash
svn co https://plugins.svn.wordpress.org/kloudstack-azure-observability/ wporg-svn
cd wporg-svn
```

Keep this checkout **outside** the Git repository. Two version control systems sharing a working
directory is a reliable way to commit `.svn` into Git or `.git` into SVN.

---

## 3. Repository layout

```
/assets      banners, icons, screenshots      — NOT shipped to users
/trunk       a copy of the newest release     — what gets tagged
/tags/2.0.6  an immutable copy of one release — what users download
/branches    unused; we do not branch here
```

Three rules that follow from this:

- **`/assets` is never inside `/trunk`.** Assets are served on the plugin's directory page and
  must not be in the download. Putting them in trunk ships hundreds of KB of PNGs to every user.
- **`/tags/<version>` is written once and never edited.** If a release is wrong, ship a new
  version. Editing a tag changes what users already installed and breaks checksums.
- **`/trunk` should mirror the newest release.** WordPress.org reads plugin headers from trunk
  for some directory metadata even when `Stable tag` points elsewhere.

---

## 4. `Stable tag` — the field that decides everything

In `readme.txt`:

```
Stable tag: 2.0.6
```

This single line determines **which directory users download from**. It is the most dangerous
field in the plugin.

- `Stable tag: 2.0.6` → users get `/tags/2.0.6`
- `Stable tag: trunk` → users get `/trunk` (do not do this; it means every trunk commit ships
  instantly to every site)
- A tag named in `Stable tag` that **does not exist** → the directory falls back to trunk, and a
  half-finished trunk goes out to everyone

**Always commit the tag directory first, then update `Stable tag` last.** That ordering means
there is never a moment where `Stable tag` points at something that is not fully uploaded.

The `readme.txt` inside `/trunk` is the one the directory reads for `Stable tag`. Keep the
version numbers consistent across all four places:

| Location | Field |
|---|---|
| `readme.txt` | `Stable tag: X.Y.Z` |
| `kloudstack-azure-observability.php` | `* Version: X.Y.Z` |
| `kloudstack-azure-observability.php` | `const VERSION = 'X.Y.Z';` |
| Git | tag `vX.Y.Z` |

CI already enforces that the Git tag matches the plugin header (`release.yml`, "Tagged version
must match the plugin header"). It does **not** check SVN.

---

## 5. Assets

Assets live in **`.wordpress-org/`** in this repository and are copied to `/assets` in SVN. They
are **excluded from the plugin package** — `release.yml` excludes `.wordpress-org` from the ZIP,
so nothing here reaches a user's site.

WordPress.org matches assets **by filename**. A file with the wrong name is silently ignored, so
the files are stored under their final names rather than being renamed at publish time.

| Required filename | Purpose | Status |
|---|---|---|
| `icon-128x128.png` | directory listing icon | ✅ |
| `icon-256x256.png` | retina icon | ✅ |
| `banner-772x250.png` | plugin page header | ✅ |
| `banner-1544x500.png` | retina header | ✅ |
| `screenshot-1.png` | settings screen | ✅ |
| `screenshot-2.png` | diagnostics self-test | ✅ |

> **Screenshot order is fixed by `readme.txt`, not by the files.** The numbered captions under
> `== Screenshots ==` are matched to `screenshot-N.png` **by number**: caption 1 describes the
> settings screen, caption 2 the self-test. Swapping the images without editing the captions
> silently mislabels both — and because `readme.txt` is baked into an immutable tag, correcting
> the captions means shipping a new version, whereas swapping the PNGs is free.
>
> When replacing a screenshot, open it and check it against the caption it will inherit.

Assets can be committed at any time and take effect within minutes — they are not tied to a
release. That is also why the deploy workflow (§11) checks them out from the default branch
rather than from the tag being published: the newest artwork is always the correct artwork.

---

## 6. Publishing a release

> **The normal route is the GitHub workflow in §11.** It performs everything below, with the
> version and asset checks automated and a dry run available. Read this section to understand what
> the workflow does, or to publish by hand when it cannot be used — see §10.

### 6.1 Build the artifact

Releases are built by CI, not by hand. Tagging is what triggers it:

```bash
git tag v2.0.6
git push origin v2.0.6
```

`release.yml` then builds `kloudstack-azure-observability-<version>.zip`, a `.sha256`
alongside it, and the MU-loader used by stack images.

### 6.2 Use the released ZIP as the source for SVN

**Do not copy files from a Git working tree into SVN.** Unpack the GitHub release asset instead.
The ZIP is what Plugin Check was run against and what the reviewer assessed; copying from a
working tree risks shipping something subtly different — a stray local edit, an untracked file, a
directory the exclude list would have removed.

```bash
# from the SVN checkout
curl -sSLO https://github.com/KloudStack-Hosting/kloudstack-azure-observability/releases/download/v2.0.6/kloudstack-azure-observability-2.0.6.zip
curl -sSLO https://github.com/KloudStack-Hosting/kloudstack-azure-observability/releases/download/v2.0.6/kloudstack-azure-observability-2.0.6.zip.sha256
sha256sum -c kloudstack-azure-observability-2.0.6.zip.sha256   # must print: OK
unzip -q kloudstack-azure-observability-2.0.6.zip -d /tmp/wporg-build
```

The ZIP contains a single top-level directory named after the slug. Its contents — and only its
contents — go into trunk:

```
LICENSE
kloudstack-azure-observability.php
languages/
readme.txt
src/
uninstall.php
```

### 6.3 Replace trunk

```bash
# from the SVN checkout root
rm -rf trunk/*
cp -r /tmp/wporg-build/kloudstack-azure-observability/* trunk/

# SVN does not detect adds and deletes on its own
svn add --force trunk/
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

svn status          # review carefully before committing
```

`svn status` codes: `A` added, `D` deleted, `M` modified, `?` untracked (needs `svn add`),
`!` missing (needs `svn rm`).

### 6.4 Create the tag

Copy **server-side** — it is instant and does not re-upload the files:

```bash
svn cp trunk tags/2.0.6
```

### 6.5 Commit

```bash
svn ci -m "Release 2.0.6"
```

One commit for trunk and the tag together. SVN commits are **immediate and public** — there is no
staging, no amend, and no force-push. Re-read `svn status` before pressing enter.

### 6.6 Verify

- <https://wordpress.org/plugins/kloudstack-azure-observability/> shows the new version
  (allow ~15 minutes for the directory to refresh)
- The "Download" button serves the new version
- wp-admin on a test site offers the update

---

## 7. Release checklist

Copy this into the release PR or issue.

**Before tagging**

- [ ] Version bumped in all four places (§4)
- [ ] `readme.txt` changelog and `== Upgrade Notice ==` entries added
- [ ] `Tested up to:` still accurate for the current WordPress release
- [ ] `.pot` regenerated if any translatable string changed —
      `wp i18n make-pot . languages/kloudstack-azure-observability.pot --slug=kloudstack-azure-observability --domain=kloudstack-azure-observability --exclude=vendor,tests,benchmark,docs,scripts,legacy,loader,node_modules`
      (CI fails the build if this is stale)
- [ ] CI green on `main`

**Before publishing to SVN**

- [ ] GitHub release exists with ZIP + `.sha256`
- [ ] Plugin Check run against the **ZIP**, not the repo — no errors at
      `--severity=1 --include-experimental`
- [ ] **Deploy workflow run with `dry_run` ticked, and green** (§11)
- [ ] If publishing by hand instead: `sha256sum -c` passes, and `svn status` reviewed line by
      line — no `.git`, `tests/`, `legacy/`, `vendor/`, `node_modules/`, `composer.*`
- [ ] `/assets` unchanged unless artwork actually changed

**After publishing**

- [ ] `svn ls .../trunk` and `.../tags/<version>` are populated — instant, and the authoritative
      check; the directory page lags behind it
- [ ] Directory page shows the new version (~15 minutes)
- [ ] **Screenshots render under the right captions** — no automated check can prove this
- [ ] `observability-version.txt` in `KloudStack ACR Images` bumped to the same version
- [ ] WordPress image rebuilt and promoted so managed stacks pick it up

---

## 8. Things that will bite

**SVN is not Git.** `svn ci` publishes immediately to a public repository. There is no local
commit, no rebase, no amend, and no way to remove a bad commit — only a new one on top.

**Never edit a tag.** Users who already installed that version, and any checksum taken of it,
both assume it is immutable. Ship a patch version instead.

**The two URLs update at different speeds, and that is the diagnostic.** SVN reflects a commit
instantly and is authoritative; the directory page has to import and rebuild before it catches up.
So: SVN populated but the page stale means wait. SVN empty means the commit never happened, and no
amount of waiting will change it. Always check SVN first.

**The commit email contains the whole diff.** WordPress.org mails every commit to the plugin
author, and a first publish diffs the entire codebase — 29 files, unreadably long. That is normal.
Later releases diff only what changed, because trunk is updated in place rather than replaced.

**`Stable tag` pointing at a missing tag falls back to trunk.** That is how a half-finished trunk
ends up installed on live sites. Commit the tag first; update `Stable tag` last.

**Assets are matched by filename, not by content.** `my-screenshot.png` is ignored. Only
`screenshot-N.png`, `icon-*.png` and `banner-*.png` are recognised.

**The exclude list is case-sensitive — and this has already bitten once.** Assets used to live in
`Screenshot/`, excluded from the ZIP by that exact spelling. Saving new files into `screenshot/`
on Windows looked identical locally, because the filesystem is case-insensitive and
`core.ignorecase` hides the difference from `git status` — but CI runs on Linux, where
`--exclude 'Screenshot'` does not match `screenshot/`, and the next release would have shipped
~965 KB of artwork inside every user's plugin.

The directory is now `.wordpress-org/`, which removes the trap rather than relocating it:

- it is excluded by name in `release.yml`, as before, **and**
- it is a dotfile, so `release.yml`'s "Nothing hidden should ship" guard fails the build loudly if
  it ever reaches the staged package — a second layer `Screenshot/` never had.

Do not reintroduce a non-hidden assets directory.

**Do not commit development files.** `tests/`, `legacy/`, `.github/`, `composer.json`,
`composer.lock`, `vendor/`, `node_modules/`. The release ZIP already excludes all of these — one
more reason to build trunk from the ZIP rather than from a working tree.

**`legacy/` contains a raw `<script>` tag.** It is excluded from the package and must stay
excluded. WordPress.org flags inline script tags, and a reviewer finding one in a shipped file
after approval is a much slower conversation than one before it.

---

## 9. Rolling back

There is no rollback in SVN. To withdraw a bad release:

1. Point `Stable tag` in `trunk/readme.txt` back at the previous good version
2. `svn ci -m "Revert stable tag to 2.0.5 while 2.0.6 is investigated"`
3. Fix forward and release a new version

Users who already updated stay on the bad version until the next release — which is the real
reason for the pre-commit checklist.

---

## 10. Publishing by hand (fallback)

Use this only when the workflow in §11 cannot run — GitHub Actions unavailable, a broken deploy
action, or a `Stable tag` that needs correcting urgently under §9.

Requires a local SVN client (§2) and your WordPress.org credentials on the workstation, which is
the reason it is the fallback and not the default.

```bash
svn co https://plugins.svn.wordpress.org/kloudstack-azure-observability/ wporg-svn
cd wporg-svn

# assets — only if artwork changed; filenames must be exactly as in §5
cp /path/to/.wordpress-org/* assets/

# trunk from the verified release ZIP — see §6.2
rm -rf trunk/*
cp -r /tmp/wporg-build/kloudstack-azure-observability/* trunk/
svn add --force trunk/
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

svn cp trunk tags/<version>
svn status                       # review line by line
svn ci -m "Release <version>"
```

SVN commits atomically, so trunk and the tag land in one revision and there is never a moment
where `Stable tag` names a tag that does not exist.

**Delete the working copy afterwards.** A stale checkout holding uncommitted adds against a
repository that has since moved on is a live hazard, not a backup.

Then work through §6.6 and the "After publishing" checklist in §7.

> **History.** 2.0.6 was the first publish; `/trunk`, `/tags` and `/assets` were empty until then.
> It went out via the §11 workflow at r3646533 on 2026-08-14, after a dry run.

---

## 11. Publishing from GitHub (the normal route)

`.github/workflows/wporg-deploy.yml` does everything in §6 as a manually triggered job, so no SVN
client, no local checkout, and no credentials on a workstation are needed.

**Actions → Deploy to WordPress.org → Run workflow**

| Input | Meaning |
|---|---|
| `tag` | the released tag to publish, e.g. `v2.0.6` |
| `dry_run` | **defaults to ticked.** Does everything except the final `svn ci` |

It is `workflow_dispatch` only — never automatic on push or on tag. Publishing stays a decision
someone makes, for the reasons in §1: a release goes out once, when it is ready.

### What it does

1. Checks out the **default branch** for `.wordpress-org/` — assets are live and not tied to a
   release, so the newest artwork is always correct, including for a tag cut before it existed
2. Downloads the release ZIP and `.sha256` **for the requested tag** and verifies the checksum —
   trunk is built from the published artifact, never from a working tree (§6.2)
3. Fails if the plugin header, the `VERSION` constant, or `readme.txt`'s `Stable tag` inside that
   ZIP disagree with the version being published
4. Fails if any of the six required asset filenames is missing
5. Deploys trunk, `tags/<version>` and `/assets` in one commit

Steps 3 and 4 are deliberately checked against the **artifact**, not the repository. CI already
checks the repo at tag time; this catches the different failure of publishing the wrong ZIP.

### Secrets

| Secret | Value |
|---|---|
| `SVN_USERNAME` | the WordPress.org account username |
| `SVN_PASSWORD` | a **dedicated SVN password** — Account Settings → [SVN password](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password) |

Use an SVN-specific password, not the account password. It is scoped to SVN and can be revoked and
regenerated on its own, so a leak is contained and rotation costs nothing. Rotate it if it is ever
pasted anywhere it should not have been — a chat log, a ticket, a screen share.

Consider putting the job in a GitHub Environment with a required reviewer if you want a second
pair of eyes on the one action that cannot be undone.

### Always dry run first

`svn ci` is immediate, public and irreversible. The dry run exercises the download, the checksum,
every version assertion, the asset check and the full SVN checkout and staging — everything except
the commit. It costs two minutes and is the only rehearsal available.
