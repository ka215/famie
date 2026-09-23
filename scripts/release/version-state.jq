# Inputs: version.json, backend/package.json, frontend/package.json (jq --slurp).
def stable_version:
  if type == "string" then test("^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$") else false end;
def parts: split(".") | map(tonumber);
if length != 3 then error("Expected version.json and two package files") else . end
| .[0] as $release | .[1].version as $backend | .[2].version as $frontend
| if (($release.current | stable_version) and ($release.next | stable_version)) | not
  then error("current and next must be stable X.Y.Z versions without leading zeroes")
  elif ($release.next | parts) <= ($release.current | parts)
  then error("next must be greater than current")
  elif $backend != $frontend then error("Backend and frontend versions differ")
  elif $backend == $release.current then [$release.current, $release.next, "current"]
  elif $backend == $release.next then [$release.current, $release.next, "next"]
  else error("Package versions must both equal current or both equal next") end
| @tsv
