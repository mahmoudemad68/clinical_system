const liveBlobUrls = new Set<string>();

export function registerBlobUrl(url: string): void {
  liveBlobUrls.add(url);
}

export function unregisterBlobUrl(url: string): void {
  liveBlobUrls.delete(url);
}

/** Revoke every Blob URL created by the reviewer download helper. */
export function revokeAllBlobUrls(): void {
  for (const url of liveBlobUrls) {
    URL.revokeObjectURL(url);
  }
  liveBlobUrls.clear();
}
