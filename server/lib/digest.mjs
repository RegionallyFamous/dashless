import { createHash, randomUUID } from "node:crypto";

function textField(value) {
  if (typeof value === "string") return value;
  if (!value || typeof value !== "object") return "";
  return value.raw ?? value.rendered ?? "";
}

function sortedIds(value) {
  return Array.isArray(value)
    ? [...new Set(value.filter(Number.isInteger))].sort((a, b) => a - b)
    : [];
}

export function canonicalPost(post, postType = "post") {
  return {
    post_type: postType,
    id: Number(post.id),
    slug: String(post.slug ?? ""),
    title: textField(post.title),
    content: textField(post.content),
    excerpt: textField(post.excerpt),
    featured_media: Number(post.featured_media || 0),
    parent: postType === "page" ? Number(post.parent || 0) : 0,
    menu_order: postType === "page" ? Number(post.menu_order || 0) : 0,
    categories: sortedIds(post.categories),
    tags: sortedIds(post.tags),
  };
}

export function contentDigest(post, postType = "post") {
  return createHash("sha256")
    .update(JSON.stringify(canonicalPost(post, postType)), "utf8")
    .digest("hex");
}

export function newToken() {
  return randomUUID();
}

export function safeId(value) {
  return createHash("sha256").update(String(value), "utf8").digest("hex").slice(0, 16);
}
