#!/usr/bin/env bash
# Prove CI is on the reviewed candidate, not GitHub's synthetic pull_request
# merge commit (github.sha / GITHUB_SHA on that event).
set -euo pipefail

expected="${CANDIDATE_SHA:-}"
if [[ -z "${expected}" ]]; then
  echo "::error::CANDIDATE_SHA is not set"
  exit 1
fi

checked="$(git rev-parse HEAD)"
merge_ref="${GITHUB_SHA:-}"

echo "expected_candidate_sha=${expected}"
echo "checked_out_sha=${checked}"
echo "github_sha=${merge_ref}"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  {
    echo "## Candidate SHA"
    echo ""
    echo "- expected_candidate_sha: \`${expected}\`"
    echo "- checked_out_sha: \`${checked}\`"
    echo "- github_sha: \`${merge_ref:-unset}\`"
  } >> "${GITHUB_STEP_SUMMARY}"
fi

if [[ -n "${merge_ref}" && "${merge_ref}" != "${expected}" ]]; then
  echo "note: github.sha is the synthetic merge commit; evidence uses the PR head"
fi

if [[ "${checked}" != "${expected}" ]]; then
  echo "::error::Checked-out SHA ${checked} does not match candidate ${expected}"
  exit 1
fi
