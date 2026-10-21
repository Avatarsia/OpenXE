#!/usr/bin/env python3
"""Validate the production manifest and declared branch dependencies."""

import argparse
import json
from pathlib import Path
import subprocess
import sys
import uuid


def git_succeeds(*args):
    return subprocess.run(
        ["git", *args], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    ).returncode == 0


def valid_branch(branch):
    return (
        isinstance(branch, str)
        and not branch.startswith("-")
        and git_succeeds("check-ref-format", "refs/heads/" + branch)
    )


def validate(manifest, dependencies):
    branches = []
    for line in manifest.read_text(encoding="utf-8").splitlines():
        branch = line.strip()
        if not branch or branch.startswith("#"):
            continue
        if not valid_branch(branch):
            raise ValueError("Invalid branch name: " + branch)
        if branch in branches:
            raise ValueError("Duplicate branch: " + branch)
        branches.append(branch)
    if not branches:
        raise ValueError("Manifest contains no branches")

    rules = json.loads(dependencies.read_text(encoding="utf-8"))
    if not isinstance(rules, dict):
        raise ValueError("Dependency configuration must be an object")
    requires = rules.get("requires")
    if not isinstance(requires, dict):
        raise ValueError("requires must be an object")
    first, last = rules.get("first"), rules.get("last")
    if not valid_branch(first) or not valid_branch(last):
        raise ValueError("first and last must be valid branch names")
    if branches[0] != first:
        raise ValueError(first + " must be first in the manifest")
    if branches[-1] != last:
        raise ValueError(last + " must be last in the manifest")

    for branch in branches:
        if not git_succeeds("rev-parse", "--verify", "refs/remotes/origin/" + branch + "^{commit}"):
            raise ValueError("Missing remote branch: origin/" + branch)

    positions = {branch: index for index, branch in enumerate(branches)}
    for child, parents in requires.items():
        if not valid_branch(child) or not isinstance(parents, list) or not all(valid_branch(p) for p in parents):
            raise ValueError("Dependencies must map valid branch names to lists of branch names")
        if child not in positions:
            continue
        for parent in parents:
            if parent not in positions:
                raise ValueError(child + " requires active branch " + parent)
            if positions[parent] >= positions[child]:
                raise ValueError(parent + " must precede " + child)
            if not git_succeeds(
                "merge-base", "--is-ancestor",
                "refs/remotes/origin/" + parent, "refs/remotes/origin/" + child,
            ):
                raise ValueError(parent + " is not an ancestor of " + child)
    return branches


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, default=Path(".github/integration-branches.txt"))
    parser.add_argument("--dependencies", type=Path, default=Path(".github/integration-dependencies.json"))
    parser.add_argument("--github-output", type=Path)
    args = parser.parse_args()
    try:
        branches = validate(args.manifest, args.dependencies)
        if args.github_output:
            delimiter = "branches_" + uuid.uuid4().hex
            with args.github_output.open("a", encoding="utf-8", newline="\n") as output:
                output.write("branches<<" + delimiter + "\n" + "\n".join(branches) + "\n" + delimiter + "\n")
        print("Validated " + str(len(branches)) + " integration branches and their dependencies")
        return 0
    except (OSError, ValueError) as error:
        print("::error::" + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
