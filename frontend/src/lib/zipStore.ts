/**
 * Uncompressed ZIP (store method). Enough for a KMZ: the PNG is already compressed,
 * and the KML is tiny.
 */

const CRC_TABLE = new Uint32Array(256)
for (let i = 0; i < 256; i++) {
  let crc = i
  for (let bit = 0; bit < 8; bit++) {
    crc = crc & 1 ? (0xedb88320 ^ (crc >>> 1)) : crc >>> 1
  }
  CRC_TABLE[i] = crc >>> 0
}

function crc32(data: Uint8Array): number {
  let crc = 0xffffffff
  for (let i = 0; i < data.length; i++) {
    crc = CRC_TABLE[(crc ^ data[i]) & 0xff] ^ (crc >>> 8)
  }
  return (crc ^ 0xffffffff) >>> 0
}

function dosDateTime(now = new Date()): { time: number; date: number } {
  return {
    time:
      ((now.getHours() & 0x1f) << 11)
      | ((now.getMinutes() & 0x3f) << 5)
      | (Math.floor(now.getSeconds() / 2) & 0x1f),
    date:
      (((now.getFullYear() - 1980) & 0x7f) << 9)
      | (((now.getMonth() + 1) & 0x0f) << 5)
      | (now.getDate() & 0x1f),
  }
}

function writeUint16(target: Uint8Array, offset: number, value: number): void {
  target[offset] = value & 0xff
  target[offset + 1] = (value >>> 8) & 0xff
}

function writeUint32(target: Uint8Array, offset: number, value: number): void {
  target[offset] = value & 0xff
  target[offset + 1] = (value >>> 8) & 0xff
  target[offset + 2] = (value >>> 16) & 0xff
  target[offset + 3] = (value >>> 24) & 0xff
}

export function zipStore(files: { name: string; data: Uint8Array }[]): Uint8Array {
  const encoder = new TextEncoder()
  const stamp = dosDateTime()
  const locals: Uint8Array[] = []
  const centrals: Uint8Array[] = []
  let offset = 0

  for (const file of files) {
    const name = encoder.encode(file.name)
    const { data } = file
    const crc = crc32(data)
    const local = new Uint8Array(30 + name.length + data.length)
    writeUint32(local, 0, 0x04034b50)
    writeUint16(local, 4, 20)
    writeUint16(local, 8, 0)
    writeUint16(local, 10, stamp.time)
    writeUint16(local, 12, stamp.date)
    writeUint32(local, 14, crc)
    writeUint32(local, 18, data.length)
    writeUint32(local, 22, data.length)
    writeUint16(local, 26, name.length)
    local.set(name, 30)
    local.set(data, 30 + name.length)
    locals.push(local)

    const central = new Uint8Array(46 + name.length)
    writeUint32(central, 0, 0x02014b50)
    writeUint16(central, 4, 20)
    writeUint16(central, 6, 20)
    writeUint16(central, 10, 0)
    writeUint16(central, 12, stamp.time)
    writeUint16(central, 14, stamp.date)
    writeUint32(central, 16, crc)
    writeUint32(central, 20, data.length)
    writeUint32(central, 24, data.length)
    writeUint16(central, 28, name.length)
    writeUint32(central, 42, offset)
    central.set(name, 46)
    centrals.push(central)
    offset += local.length
  }

  const centralSize = centrals.reduce((sum, part) => sum + part.length, 0)
  const end = new Uint8Array(22)
  writeUint32(end, 0, 0x06054b50)
  writeUint16(end, 8, files.length)
  writeUint16(end, 10, files.length)
  writeUint32(end, 12, centralSize)
  writeUint32(end, 16, offset)

  const total = offset + centralSize + end.length
  const archive = new Uint8Array(total)
  let cursor = 0
  for (const part of locals) {
    archive.set(part, cursor)
    cursor += part.length
  }
  for (const part of centrals) {
    archive.set(part, cursor)
    cursor += part.length
  }
  archive.set(end, cursor)
  return archive
}
