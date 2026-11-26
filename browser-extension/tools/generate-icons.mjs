// Deterministic raster rendition of DupIssue's overlapping issue cards.
import { deflateSync } from "node:zlib";
import { mkdir, writeFile } from "node:fs/promises";
const crc = (b) => {
  let c = 0xffffffff;
  for (const n of b) {
    c ^= n;
    for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (c & 1 ? 0xedb88320 : 0);
  }
  return (c ^ 0xffffffff) >>> 0;
};
const chunk = (name, data) => {
  const n = Buffer.from(name),
    size = Buffer.alloc(4),
    sum = Buffer.alloc(4);
  size.writeUInt32BE(data.length);
  sum.writeUInt32BE(crc(Buffer.concat([n, data])));
  return Buffer.concat([size, n, data, sum]);
};
await mkdir(new URL("../icons/", import.meta.url), { recursive: true });
for (const s of [16, 48, 128]) {
  const pixels = Buffer.alloc((s * 4 + 1) * s);
  for (let y = 0; y < s; y++)
    for (let x = 0; x < s; x++) {
      let color = [111, 66, 193, 255],
        u = x / s,
        v = y / s;
      if (u > 0.19 && u < 0.64 && v > 0.18 && v < 0.72)
        color = [198, 179, 230, 255];
      if (u > 0.32 && u < 0.81 && v > 0.28 && v < 0.84)
        color = [255, 255, 255, 255];
      if (
        u > 0.41 &&
        u < 0.7 &&
        ((v > 0.39 && v < 0.44) || (v > 0.51 && v < 0.55))
      )
        color = [169, 147, 207, 255];
      if (
        Math.hypot(u - 0.46, v - 0.69) < 0.06 ||
        Math.hypot(u - 0.68, v - 0.69) < 0.06
      )
        color = [111, 66, 193, 255];
      Buffer.from(color).copy(pixels, y * (s * 4 + 1) + 1 + x * 4);
    }
  const header = Buffer.alloc(13);
  header.writeUInt32BE(s);
  header.writeUInt32BE(s, 4);
  header[8] = 8;
  header[9] = 6;
  await writeFile(
    new URL("../icons/icon" + s + ".png", import.meta.url),
    Buffer.concat([
      Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
      chunk("IHDR", header),
      chunk("IDAT", deflateSync(pixels)),
      chunk("IEND", Buffer.alloc(0)),
    ]),
  );
}
