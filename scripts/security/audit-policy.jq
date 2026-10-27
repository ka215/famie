# No vulnerability exceptions. High, critical, and unknown severities block.
{
  accepted_exceptions: [],
  blocking: [.advisories[] |
    select(.severity != "info" and .severity != "low" and .severity != "moderate") |
    {module_name,url,severity}]
}
