import { cp, readFile, writeFile, mkdir } from "node:fs/promises";
const args = process.argv.slice(2);
if (args.length > 1 || (args.length === 1 && args[0] !== "--qa")) {
  throw new Error("Usage: node build-local.mjs [--qa]");
}
const origin =
  args[0] === "--qa" ? "http://127.0.0.1:18080" : "http://127.0.0.1:8000";
const source = new URL("../", import.meta.url),
  output = new URL("../../.local-extension/", import.meta.url);
await mkdir(output, { recursive: true });
await cp(source, output, {
  recursive: true,
  filter: (p) => !p.includes("/tests") && !p.includes("/tools"),
});
const manifest = JSON.parse(
  await readFile(new URL("manifest.json", output), "utf8"),
);
manifest.name = "DupIssue (LOCAL ONLY)";
manifest.host_permissions = [origin + "/*"];
await writeFile(
  new URL("manifest.json", output),
  JSON.stringify(manifest, null, 2) + "\n",
);
await writeFile(
  new URL("config.mjs", output),
  "export const API_ORIGIN = '" + origin + "';\n",
);
