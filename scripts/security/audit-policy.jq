# Approved 2026-10-26; expires at 2026-11-10 00:00 JST (exclusive).
def forge_exception:
  .module_name == "node-forge"
  and .url == "https://github.com/advisories/GHSA-86w9-cpqp-85rv"
  and .severity == "high"
  and $now < ("2026-11-09T15:00:00Z" | fromdateiso8601)
  and (.findings | type == "array" and length > 0)
  and all(.findings[];
    .version == "1.4.0"
    and .paths == [".>nuxt>@nuxt/nitro-server>nitropack>listhen>node-forge"]);

{
  accepted_exceptions: [.advisories[] | select(forge_exception) |
    {module_name,url,severity,version:"1.4.0",expires_at:"2026-11-10T00:00:00+09:00",
     reason:"Approved development HTTPS dependency; Apache static frontend and PHP API only"}],
  blocking: [.advisories[] |
    select((.severity != "info" and .severity != "low" and .severity != "moderate") and (forge_exception | not)) |
    {module_name,url,severity}]
}
