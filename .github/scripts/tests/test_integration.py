import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


SCRIPTS = Path(__file__).resolve().parents[1]
WORKFLOW = SCRIPTS.parent / "workflows" / "build-production.yml"
CI = "feature/ci-workflow"
PAGINATION = "fix/woocommerce-order-import-pagination"
BATCH = "feature/woocommerce-batch-stock-sync"
SDK = "refactor/woocommerce-composer-sdk"
PARITY = "feature/woocommerce-functional-parity"
OVERRIDE = "config/production-remote-override"
UPSTREAM_BASE = "1.13"
UPSTREAM_REF = "refs/remotes/upstream/" + UPSTREAM_BASE


class IntegrationConfigTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="openxe-ci-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        self.git("init", "-q")
        self.git("config", "user.name", "Integration Test")
        self.git("config", "user.email", "integration-test@localhost")
        (self.repo / "upstream-patch.txt").write_text("upstream patch\n", encoding="utf-8")
        self.git("add", ".")
        self.git("commit", "-qm", "upstream")
        self.base = self.git("rev-parse", "HEAD")
        self.git("update-ref", UPSTREAM_REF, self.base)
        self.branches = [CI, PAGINATION, BATCH, SDK, PARITY, OVERRIDE]
        parents = {BATCH: PAGINATION, SDK: BATCH, PARITY: SDK}
        self.tips = {}
        for branch in self.branches:
            parent = self.tips.get(parents.get(branch), self.base)
            tip = self.git("commit-tree", "HEAD^{tree}", "-p", parent, "-m", branch)
            self.tips[branch] = tip
            self.git("update-ref", "refs/remotes/origin/" + branch, tip)
        self.rules = {
            "first": CI,
            "last": OVERRIDE,
            "requires": {BATCH: [PAGINATION], SDK: [BATCH], PARITY: [SDK]},
        }
        self.manifest = self.root / "manifest.txt"
        self.dependencies = self.root / "dependencies.json"
        self.output = self.root / "github-output.txt"

    def git(self, *args):
        result = subprocess.run(
            ["git", *args], cwd=self.repo, capture_output=True, text=True,
            encoding="utf-8", check=True,
        )
        return result.stdout.strip()

    def validate(self, branches=None, rules=None):
        self.manifest.write_text(
            "# test manifest\n\n" + "\n".join(self.branches if branches is None else branches) + "\n",
            encoding="utf-8",
        )
        self.dependencies.write_text(json.dumps(self.rules if rules is None else rules), encoding="utf-8")
        return subprocess.run(
            [sys.executable, str(SCRIPTS / "validate-integration.py"),
             "--manifest", str(self.manifest), "--dependencies", str(self.dependencies),
             "--github-output", str(self.output)],
            cwd=self.repo, capture_output=True, text=True, encoding="utf-8",
        )

    def assert_rejected(self, result, message):
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(message, result.stderr)
        self.assertFalse(self.output.exists(), "Invalid config must not expose branch outputs")

    def workflow_script(self, name):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        section = workflow.split("      - name: " + name + "\n", 1)[1]
        script = section.split("        run: |\n", 1)[1].split("\n      - name:", 1)[0]
        return "\n".join(line[10:] for line in script.splitlines())

    def run_workflow_script(self, name):
        script = self.workflow_script(name).replace(
            "${{ steps.manifest.outputs.branches }}", "\n".join(self.branches),
        )
        bash = shutil.which("bash")
        if os.name == "nt":
            bash = str(Path(shutil.which("git")).parents[1] / "bin" / "bash.exe")
        return subprocess.run(
            [bash, "-eo", "pipefail", "-c", script], cwd=self.repo,
            env={**os.environ, "GITHUB_OUTPUT": self.output.as_posix(), "UPSTREAM_BASE": UPSTREAM_BASE},
            capture_output=True, text=True, encoding="utf-8",
        )

    def test_valid_config_emits_branches_in_order(self):
        result = self.validate()
        self.assertEqual(result.returncode, 0, result.stderr)
        content = self.output.read_text(encoding="utf-8").splitlines()
        self.assertEqual(content[1:-1], self.branches)

    def test_empty_manifest_is_rejected(self):
        self.assert_rejected(self.validate([]), "Manifest contains no branches")

    def test_duplicate_branch_is_rejected(self):
        self.assert_rejected(self.validate(self.branches + [BATCH]), "Duplicate branch")

    def test_missing_remote_branch_is_rejected(self):
        self.git("update-ref", "-d", "refs/remotes/origin/" + BATCH)
        self.assert_rejected(self.validate(), "Missing remote branch")

    def test_missing_dependency_is_rejected(self):
        self.assert_rejected(self.validate([b for b in self.branches if b != PAGINATION]), "requires active branch")

    def test_dependency_must_precede_child(self):
        branches = [CI, BATCH, PAGINATION, SDK, OVERRIDE]
        self.assert_rejected(self.validate(branches), "must precede")

    def test_dependency_must_be_in_child_history(self):
        unrelated = self.git("commit-tree", "HEAD^{tree}", "-p", self.base, "-m", "independent batch")
        self.git("update-ref", "refs/remotes/origin/" + BATCH, unrelated)
        self.assert_rejected(self.validate(), "is not an ancestor")

    def test_ci_branch_must_be_first(self):
        branches = [PAGINATION, CI, BATCH, SDK, OVERRIDE]
        self.assert_rejected(self.validate(branches), "must be first")

    def test_remote_override_must_be_last(self):
        branches = [CI, PAGINATION, BATCH, OVERRIDE, SDK]
        self.assert_rejected(self.validate(branches), "must be last")

    def test_inactive_child_can_keep_dependency_declaration(self):
        result = self.validate([CI, PAGINATION, OVERRIDE])
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_invalid_branch_name_is_rejected(self):
        self.assert_rejected(self.validate([CI, "invalid branch", OVERRIDE]), "Invalid branch name")

    def test_malformed_dependencies_are_rejected(self):
        self.assert_rejected(self.validate(rules={"first": CI, "last": OVERRIDE, "requires": []}), "requires must be an object")

    def test_loader_uses_ci_config_instead_of_stale_production(self):
        config = self.repo / ".github"
        config.mkdir()
        (config / "integration-branches.txt").write_text("\n".join(self.branches) + "\n", encoding="utf-8")
        (config / "integration-dependencies.json").write_text(json.dumps(self.rules), encoding="utf-8")
        if (SCRIPTS / "validate-integration.py").exists():
            (config / "scripts").mkdir()
            shutil.copyfile(SCRIPTS / "validate-integration.py", config / "scripts" / "validate-integration.py")
        self.git("add", ".github")
        self.git("commit", "-qm", "new control config")
        self.git("update-ref", "refs/remotes/origin/" + CI, self.git("rev-parse", "HEAD"))
        self.git("checkout", "-q", "--detach", self.base)
        config.mkdir(exist_ok=True)
        (config / "integration-branches.txt").write_text("obsolete-only\n", encoding="utf-8")
        self.git("add", ".github")
        self.git("commit", "-qm", "stale production config")
        result = self.run_workflow_script("Load integration branch manifest")
        self.assertEqual(result.returncode, 0, result.stderr)
        content = self.output.read_text(encoding="utf-8").splitlines()
        self.assertEqual(content[1:-1], self.branches)

    def test_build_keeps_new_upstream_patch_with_older_feature_branches(self):
        (self.repo / "upstream-patch.txt").write_text("new upstream fix\n", encoding="utf-8")
        self.git("add", ".")
        self.git("commit", "-qm", "new upstream patch")
        upstream = self.git("rev-parse", "HEAD")
        self.git("update-ref", UPSTREAM_REF, upstream)
        result = self.run_workflow_script("Pass 2 - full integration build")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.git("merge-base", upstream, "production-candidate"), upstream)
        self.assertEqual(self.git("show", "production-candidate:upstream-patch.txt"), "new upstream fix")
        self.assertEqual(self.output.read_text(encoding="utf-8").strip(), "failed=")

    def test_upstream_conflict_is_reported_instead_of_overwriting_patch(self):
        (self.repo / "upstream-patch.txt").write_text("feature implementation\n", encoding="utf-8")
        self.git("add", ".")
        self.git("commit", "-qm", "feature changes same line")
        self.git("update-ref", "refs/remotes/origin/" + BATCH, self.git("rev-parse", "HEAD"))
        self.git("checkout", "-q", "--detach", self.base)
        (self.repo / "upstream-patch.txt").write_text("new upstream fix\n", encoding="utf-8")
        self.git("add", ".")
        self.git("commit", "-qm", "upstream changes same line")
        self.git("update-ref", UPSTREAM_REF, self.git("rev-parse", "HEAD"))
        result = self.run_workflow_script("Pass 1 - isolated merge tests")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(BATCH + ":upstream-patch.txt", self.output.read_text(encoding="utf-8"))

    def test_optional_woocommerce_suite_is_skipped_when_absent(self):
        result = self.run_workflow_script("Pass 3 - lint and tests")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("WooCommerce", self.output.read_text(encoding="utf-8"))

    def test_woocommerce_suite_runs_successfully_on_candidate_files(self):
        suite = self.repo / "tests" / "woocommerce"
        suite.mkdir(parents=True)
        (suite / "run.php").write_text(
            "<?php file_put_contents(__DIR__ . '/executed', 'yes'); echo 'suite passed';\n",
            encoding="utf-8",
        )
        result = self.run_workflow_script("Pass 3 - lint and tests")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((suite / "executed").exists(), result.stdout + result.stderr)
        self.assertIn("suite passed", result.stdout)
        self.assertNotIn("WooCommerce test failed", self.output.read_text(encoding="utf-8"))

    def test_woocommerce_failure_without_output_is_reported(self):
        suite = self.repo / "tests" / "woocommerce"
        suite.mkdir(parents=True)
        (suite / "run.php").write_text("<?php exit(1);\n", encoding="utf-8")
        result = self.run_workflow_script("Pass 3 - lint and tests")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("WooCommerce test failed", self.output.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
