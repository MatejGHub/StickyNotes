const CreateStickyUtils = {
  getCurrentNoteCount() {
    return Number(window.currentNoteCount || currentNoteCount) || 0;
  },

  updateNoteCount(count) {
    currentNoteCount = count;
    try {
      window.currentNoteCount = count;
    } catch (_) {}
  },

  getMaxNotes() {
    const v = Number(window.my_ajax_object && window.my_ajax_object.max_notes);
    return Number.isFinite(v) && v > 0 ? v : 10;
  },

  focusNote(noteElement) {
    try {
      document.querySelectorAll(".sticky-note.focused").forEach((n) => {
        n.classList.remove("focused");
      });
    } catch (_) {}
    noteElement.classList.add("focused");
  },
};

// Infer current device type from viewport width so every note has data-device
function getStickyDeviceType() {
  try {
    const width =
      window.innerWidth ||
      document.documentElement.clientWidth ||
      document.body.clientWidth ||
      0;
    if (width <= 576) return "mobile";
    if (width <= 1023) return "tablet";
    return "desktop";
  } catch (_) {
    return "desktop";
  }
}

let currentNoteCount = 0;
try {
  if (typeof window.currentNoteCount === "undefined") {
    window.currentNoteCount = 0;
  }
} catch (_) {}
const maxNotes = CreateStickyUtils.getMaxNotes();

function createStickyNote(xPercent, yPercent, elementPath, options) {
  const opts = options || {};
  const skipLimit = !!opts.skipLimit;
  const markCompleted = !!opts.markCompleted;
  if (typeof elementPath === "string" && elementPath.length > 1000) {
    elementPath = elementPath.slice(0, 1000);
  }
  let effectiveCount = 0;
  try {
    effectiveCount = Array.from(
      document.querySelectorAll(".sticky-note")
    ).filter((n) => n.dataset.completed !== "1").length;
  } catch (_) {
    effectiveCount = CreateStickyUtils.getCurrentNoteCount();
  }

  if (!skipLimit && effectiveCount >= maxNotes) {
    if (window.stickyFeedback) {
      window.stickyFeedback.showNotification(
        "You reached the limit of sticky notes.",
        "warning",
        2500
      );
    } else {
      alert("You reached the limit of sticky notes.");
    }
    return null;
  }

  if (!skipLimit) {
    CreateStickyUtils.updateNoteCount(effectiveCount + 1);
  }

  const wrapper = document.createElement("div");
  wrapper.className = "sticky-note is-expanded sticky-note-wrapper";
  // Ensure each note carries its originating device for CSS and reporting
  const device = opts.device || getStickyDeviceType();
  wrapper.dataset.device = device;
  if (markCompleted) {
    wrapper.dataset.completed = "1";
  }
  document.body.appendChild(wrapper);

  CreateStickyUtils.focusNote(wrapper);

  wrapper.addEventListener("mousedown", function () {
    CreateStickyUtils.focusNote(wrapper);
  });

  const docWidth = document.documentElement.scrollWidth;
  const docHeight = document.documentElement.scrollHeight;

  const noteWidth = wrapper.offsetWidth;
  const noteHeight = wrapper.offsetHeight;

  let left = (xPercent / 100) * docWidth;
  let top = (yPercent / 100) * docHeight;

  left = Math.min(Math.max(left, 0), docWidth - noteWidth);
  top = Math.min(Math.max(top, 0), docHeight - noteHeight);

  wrapper.style.left = left + "px";
  wrapper.style.top = top + "px";
  wrapper.classList.add("visible");

  const handle = document.createElement("div");
  handle.className = "sticky-handle no-emoji";
  const titleWrap = document.createElement("div");
  titleWrap.className = "sticky-title-wrap";
  const titleIcon = document.createElement("span");
  titleIcon.className = "sticky-title-icon";
  titleIcon.textContent = "📝";
  titleIcon.title = "Rename";
  const titleText = document.createElement("span");
  titleText.className = "sticky-title-text";
  titleText.textContent = "Sticky Note";
  // Sanitize title input to prevent XSS
  function sanitizeTitleInput(input) {
    if (!input || typeof input !== "string") return "";

    // Remove HTML tags completely
    let sanitized = input.replace(/<[^>]*>/g, "");

    // Remove script-related content (additional safety)
    sanitized = sanitized.replace(/javascript:/gi, "");
    sanitized = sanitized.replace(/on\w+\s*=/gi, "");
    sanitized = sanitized.replace(/<script[^>]*>.*?<\/script>/gi, "");

    // Trim whitespace and limit length (reasonable title length)
    sanitized = sanitized.trim().substring(0, 100);

    return sanitized;
  }

  const titleInput = document.createElement("input");
  titleInput.type = "text";
  titleInput.className = "sticky-title-input sticky-title-input-hidden";
  titleInput.placeholder = "Enter note title...";
  titleInput.value = titleText.textContent;
  titleInput.maxLength = 100; // HTML attribute as additional safeguard
  function startTitleEdit() {
    titleInput.value =
      (wrapper.dataset && wrapper.dataset.title) ||
      titleText.textContent ||
      "Sticky Note";
    titleText.classList.add("sticky-title-text-hidden");
    titleText.classList.remove("sticky-title-text-visible");
    titleInput.classList.remove("sticky-title-input-hidden");
    titleInput.classList.add("sticky-title-input-visible");
    setTimeout(() => titleInput.focus(), 0);
    titleInput.select();
  }
  function finishTitleEdit(commit) {
    if (!commit) {
      titleInput.classList.add("sticky-title-input-hidden");
      titleInput.classList.remove("sticky-title-input-visible");
      titleText.classList.remove("sticky-title-text-hidden");
      titleText.classList.add("sticky-title-text-visible");
      return;
    }
    // Sanitize the input to prevent XSS attacks
    const rawTitle = titleInput.value || "";
    const newTitle = sanitizeTitleInput(rawTitle) || "Sticky Note";
    wrapper.dataset.title = newTitle;
    titleText.textContent = newTitle;
    titleInput.classList.add("sticky-title-input-hidden");
    titleInput.classList.remove("sticky-title-input-visible");
    titleText.classList.remove("sticky-title-text-hidden");
    titleText.classList.add("sticky-title-text-visible");
    saveButton.classList.remove("sticky-save-hidden");
    saveButton.classList.add("sticky-save-visible");
    try {
      saveNoteState();
    } catch (_) {}
  }
  titleIcon.addEventListener("click", startTitleEdit);
  titleText.addEventListener("dblclick", startTitleEdit);
  titleInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") finishTitleEdit(true);
    if (e.key === "Escape") finishTitleEdit(false);
  });

  // Real-time sanitization as user types
  titleInput.addEventListener("input", (e) => {
    const cursorPos = e.target.selectionStart;
    const sanitized = sanitizeTitleInput(e.target.value);
    if (sanitized !== e.target.value) {
      e.target.value = sanitized;
      // Restore cursor position after sanitization
      e.target.setSelectionRange(cursorPos, cursorPos);
    }
  });

  titleInput.addEventListener("blur", () => finishTitleEdit(true));
  titleWrap.appendChild(titleIcon);
  titleWrap.appendChild(titleText);
  titleWrap.appendChild(titleInput);

  const priorityBadge = document.createElement("span");
  priorityBadge.className = "sticky-priority-badge";
  priorityBadge.classList.add("sticky-priority-badge-margin");
  handle.appendChild(priorityBadge);

  const buttons = document.createElement("div");
  buttons.className = "sticky-buttons";

  const actions = document.createElement("div");
  actions.className = "sticky-actions";
  actions.classList.add("sticky-actions-container");
  const actionsBtn = document.createElement("span");
  actionsBtn.className = "sticky-actions-btn";
  actionsBtn.textContent = "⋮";

  const menu = document.createElement("div");
  menu.className = "sticky-actions-menu";
  menu.innerHTML =
    '<div class="sticky-actions-item" data-action="assign">Assign</div>' +
    '<div class="sticky-actions-item" data-action="priority">Priority</div>' +
    '<div class="sticky-actions-item" data-action="add-comment">Add comment</div>' +
    '<div class="sticky-actions-item" data-action="add-images">Add images</div>' +
    '<div class="sticky-actions-item" data-action="complete">Mark complete</div>' +
    '<div class="sticky-actions-item sticky-hidden" data-action="uncomplete">Unmark complete</div>';

  // Images indicator (shows when note has images)
  const imagesIndicator = document.createElement("span");
  imagesIndicator.className = "sticky-images-indicator sticky-hidden";
  imagesIndicator.title = "View images";
  imagesIndicator.dataset.count = "0";
  imagesIndicator.innerHTML =
    '<svg class="sticky-images-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3h16a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3h-3.2l-1.2-1.5A2 2 0 0 0 14.4 3H9.6a2 2 0 0 0-1.6.5L6.8 5H4zm0 2h3.2c.3 0 .6-.1.8-.3l1.3-1.6c.19-.24.48-.39.79-.39h4.4c.31 0 .6.15.79.39l1.32 1.65c.2.24.5.39.81.39H20a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1zm3.5 5.5L6 18h12l-4.5-6-3.25 4.25-2.75-3.75zM10 9.5A1.5 1.5 0 1 0 10 12a1.5 1.5 0 0 0 0-2.5z"/></svg>';
  // Order: [indicator] [actionsBtn] [menu]
  actions.appendChild(imagesIndicator);
  actions.appendChild(actionsBtn);
  actions.appendChild(menu);

  const close = document.createElement("span");
  close.className = "sticky-close";
  close.textContent = "×";

  buttons.appendChild(actions);
  buttons.appendChild(close);
  handle.appendChild(titleWrap);
  handle.appendChild(buttons);

  const textArea = document.createElement("textarea");
  textArea.className = "sticky-text";
  textArea.placeholder = "Type your note here...";
  const MAX_CHARS = 500;
  try {
    textArea.maxLength = MAX_CHARS;
  } catch (_) {}

  // Character counter UI
  const counterWrap = document.createElement("div");
  counterWrap.className = "sticky-char-counter-wrap";
  const counter = document.createElement("div");
  counter.className = "sticky-char-counter";
  const counterCount = document.createElement("span");
  counterCount.className = "sticky-char-count";
  counterCount.textContent = "0";
  const counterTotal = document.createElement("span");
  counterTotal.className = "sticky-char-total";
  counterTotal.textContent = "/" + MAX_CHARS;
  counter.appendChild(counterCount);
  counter.appendChild(counterTotal);
  counterWrap.appendChild(counter);

  // Comments toggle and badge (placed inside counter wrap; no existing classes changed)
  const commentsToggle = document.createElement("button");
  commentsToggle.type = "button";
  commentsToggle.className = "sticky-comments-toggle";
  commentsToggle.title = "Comments";
  // Replace emoji with inline SVG icon
  const commentsIcon = document.createElement("span");
  commentsIcon.className = "sticky-comments-icon";
  commentsIcon.innerHTML =
    '<svg class="sticky-comments-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12a8 8 0 0 1-8 8H8l-4 3v-5a8 8 0 1 1 17-6z"/></svg>';
  commentsToggle.appendChild(commentsIcon);
  const commentsBadge = document.createElement("span");
  commentsBadge.className = "sticky-comments-badge";
  commentsBadge.textContent = "0";
  commentsToggle.appendChild(commentsBadge);
  // Initially hidden; will show once there is at least one comment
  commentsToggle.classList.add("sticky-hidden");
  counterWrap.appendChild(commentsToggle);

  // Comments panel (hidden by default)
  const commentsPanel = document.createElement("div");
  commentsPanel.className = "sticky-comments-panel";
  const commentsList = document.createElement("div");
  commentsList.className = "sticky-comments-list";
  const commentsForm = document.createElement("div");
  commentsForm.className = "sticky-comments-form";
  const commentsInput = document.createElement("textarea");
  commentsInput.className = "sticky-comments-input";
  commentsInput.placeholder = "Write a comment...";
  const commentsAddBtn = document.createElement("button");
  commentsAddBtn.type = "button";
  commentsAddBtn.className = "sticky-comments-add";
  commentsAddBtn.textContent = "Add";
  commentsForm.appendChild(commentsInput);
  commentsForm.appendChild(commentsAddBtn);
  commentsPanel.appendChild(commentsList);
  commentsPanel.appendChild(commentsForm);

  const saveButton = document.createElement("button");
  saveButton.textContent = "Save";
  saveButton.className = "sticky-comment-save";
  saveButton.classList.add("sticky-save-hidden");

  // Images UI (kept hidden; uploads triggered from actions menu)
  const imagesWrap = document.createElement("div");
  imagesWrap.className = "sticky-images-wrap sticky-hidden";
  const imagesList = document.createElement("div");
  imagesList.className = "sticky-images-list sticky-hidden";
  imagesWrap.appendChild(imagesList);
  const hiddenFile = document.createElement("input");
  hiddenFile.type = "file";
  hiddenFile.accept = "image/*";
  hiddenFile.multiple = true;
  hiddenFile.className = "sticky-image-input";

  let imageItems = [];
  function renderImages() {
    imagesList.innerHTML = "";
    const validItems = Array.isArray(imageItems)
      ? imageItems.filter((it) => it && (it.url || it.thumb_url))
      : [];
    const count = Array.isArray(imageItems) ? imageItems.length : 0;
    imagesIndicator.dataset.count = String(count);
    imagesIndicator.title = `View images (${count})`;
    if (!count) {
      imagesIndicator.classList.add("sticky-hidden");
      imagesList.classList.add("sticky-hidden");
      return;
    }
    imagesIndicator.classList.remove("sticky-hidden");
    imagesList.classList.remove("sticky-hidden");
    validItems.forEach((img) => {
      const item = document.createElement("div");
      item.className = "sticky-image-item";
      const thumb = document.createElement("img");
      thumb.className = "sticky-image-thumb";
      thumb.src = img.thumb_url || img.url || "";
      thumb.alt = "Image";
      thumb.addEventListener("error", () => {
        const indexInAll = imageItems.indexOf(img);
        if (indexInAll > -1) {
          imageItems.splice(indexInAll, 1);
          renderImages();
        }
      });
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "sticky-image-remove";
      remove.innerHTML = "&times;";
      remove.title = "Remove";
      remove.addEventListener("click", () => {
        const indexInAll = imageItems.indexOf(img);
        if (indexInAll > -1) {
          imageItems.splice(indexInAll, 1);
        }
        renderImages();
        saveButton.classList.remove("sticky-save-hidden");
        saveButton.classList.add("sticky-save-visible");
      });
      item.appendChild(thumb);
      item.appendChild(remove);
      imagesList.appendChild(item);
    });
  }

  function uploadFiles(files) {
    if (!files || files.length === 0) return;
    // Allow selecting up to 3 images per upload selection
    const uploads = Array.from(files).slice(0, 3);
    const requests = uploads.map((file) => {
      const form = new FormData();
      form.append("action", "sticky_upload_note_image");
      form.append("nonce", (my_ajax_object && my_ajax_object.nonce) || "");
      form.append("file", file);
      return fetch(my_ajax_object.ajax_url, { method: "POST", body: form })
        .then((r) => r.json())
        .then((res) => {
          if (res && res.success && res.data && res.data.id) return res.data;
          throw new Error(
            (res && (res.error || res.data?.message)) || "Upload failed"
          );
        });
    });
    const loader = window.stickyFeedback?.showLoadingIndicator(actionsBtn);
    Promise.allSettled(requests)
      .then((outcomes) => {
        let changed = false;
        outcomes.forEach((o) => {
          if (o.status === "fulfilled") {
            imageItems.push(o.value);
            changed = true;
          }
        });
        if (changed) {
          renderImages();
          saveButton.classList.remove("sticky-save-hidden");
          saveButton.classList.add("sticky-save-visible");
        }
      })
      .finally(() => {
        if (loader) window.stickyFeedback?.hideLoadingIndicator(loader);
      });
  }

  hiddenFile.addEventListener("change", (e) => {
    uploadFiles(e.target.files);
    try {
      e.target.value = "";
    } catch (_) {}
  });

  wrapper.appendChild(handle);
  wrapper.appendChild(textArea);
  // Insert comments panel right after textarea and before the counter
  wrapper.appendChild(commentsPanel);
  wrapper.appendChild(imagesWrap);
  wrapper.appendChild(hiddenFile);
  wrapper.appendChild(counterWrap);
  wrapper.appendChild(saveButton);
  function updateCharUI() {
    try {
      const len = Math.min(textArea.value.length, MAX_CHARS);
      counterCount.textContent = String(len);
    } catch (_) {}
  }

  let assignedToValue = "";
  let isCompleted = 0;
  let priorityValue = 2;
  let commentItems = [];

  function renderComments() {
    try {
      commentsList.innerHTML = "";
      const items = Array.isArray(commentItems) ? commentItems : [];
      commentsBadge.textContent = String(items.length);
      if (items.length > 0) {
        commentsToggle.classList.remove("sticky-hidden");
      } else {
        commentsToggle.classList.add("sticky-hidden");
      }
      function formatTime(ts) {
        try {
          const str = String(ts || "");
          const m = str.match(/(\d{2}):(\d{2})/);
          if (m) return m[1] + ":" + m[2];
          const d = new Date(str);
          if (!isNaN(d)) {
            const hh = String(d.getHours()).padStart(2, "0");
            const mm = String(d.getMinutes()).padStart(2, "0");
            return hh + ":" + mm;
          }
        } catch (_) {}
        return String(ts || "");
      }

      items.forEach(function (c, idx) {
        const row = document.createElement("div");
        // Mark comments from the current viewer
        const isMe = (function () {
          try {
            const currentId = Number(
              window.my_ajax_object && window.my_ajax_object.current_user_id
            );
            if (currentId && Number(c && c.user_id) === currentId) return true;
            const curEmail =
              (window.my_ajax_object &&
                window.my_ajax_object.current_user_email) ||
              "";
            if (
              curEmail &&
              c &&
              c.user_email &&
              String(c.user_email).toLowerCase() ===
                String(curEmail).toLowerCase()
            ) {
              return true;
            }
          } catch (_) {}
          return false;
        })();
        row.className = "sticky-comment-row" + (isMe ? " me" : "");
        const meta = document.createElement("div");
        meta.className = "sticky-comment-meta";
        const who = document.createElement("span");
        who.className = "sticky-comment-user";
        who.textContent = (function () {
          const id = Number(c && c.user_id);

          // Check if this is the current user
          if (
            id &&
            window.my_ajax_object &&
            Number(window.my_ajax_object.current_user_id) === id
          ) {
            const first =
              (window.my_ajax_object &&
                window.my_ajax_object.current_user_first_name) ||
              "";
            if (typeof first === "string" && first.trim() !== "")
              return first.trim();
            const curEmail =
              (window.my_ajax_object &&
                window.my_ajax_object.current_user_email) ||
              "";
            if (typeof curEmail === "string" && curEmail.trim() !== "")
              return curEmail;
            return (
              (window.my_ajax_object &&
                window.my_ajax_object.current_user_display) ||
              "You"
            );
          }

          // Prefer user's first name if available
          if (
            c &&
            typeof c.first_name === "string" &&
            c.first_name.trim() !== ""
          ) {
            return c.first_name.trim();
          }

          // Fallback: use stored user email if available
          if (c && c.user_email && c.user_email.trim() !== "") {
            return c.user_email;
          }

          // Fallback to lookup in users array (for backward compatibility)
          if (
            window.my_ajax_object &&
            window.my_ajax_object.users &&
            Array.isArray(window.my_ajax_object.users)
          ) {
            const user = window.my_ajax_object.users.find(
              (u) => u && Number(u.id) === id
            );
            if (
              user &&
              typeof user.first_name === "string" &&
              user.first_name.trim() !== ""
            ) {
              return user.first_name.trim();
            }
            if (user && user.login) {
              return user.login;
            }
            if (user && user.email) {
              return user.email.split("@")[0]; // Show username part of email
            }
          }

          // Final fallback
          return c && c.user_id ? `User ${c.user_id}` : "Anonymous";
        })();
        const when = document.createElement("span");
        when.className = "sticky-comment-time";
        when.textContent = formatTime(c && c.created_at ? c.created_at : "");
        meta.appendChild(who);
        meta.appendChild(document.createTextNode(" • "));
        meta.appendChild(when);

        const text = document.createElement("div");
        text.className = "sticky-comment-text";
        text.textContent = c && c.content ? String(c.content) : "";

        const contentWrap = document.createElement("div");
        contentWrap.className = "sticky-comment-content";
        contentWrap.appendChild(meta);
        contentWrap.appendChild(text);
        row.appendChild(contentWrap);

        // Delete own comment button
        try {
          const currentId = Number(
            window.my_ajax_object && window.my_ajax_object.current_user_id
          );
          const isOwner = currentId && Number(c && c.user_id) === currentId;
          if (isOwner) {
            const delBtn = document.createElement("button");
            delBtn.type = "button";
            delBtn.className = "sticky-comment-delete";
            delBtn.textContent = "\u00D7"; // ×
            delBtn.title = "Delete comment";
            delBtn.addEventListener("click", function (ev) {
              ev.stopPropagation();
              const noteId =
                wrapper && wrapper.dataset ? wrapper.dataset.noteId : "";
              if (!noteId) return;
              const btnLoader =
                window.stickyFeedback?.showLoadingIndicator(delBtn);
              fetch(my_ajax_object.ajax_url, {
                method: "POST",
                headers: {
                  "Content-Type": "application/x-www-form-urlencoded",
                },
                body: new URLSearchParams({
                  action: "sticky_delete_comment",
                  nonce: my_ajax_object.nonce,
                  note_id: String(noteId),
                  index: String(idx),
                }),
              })
                .then((r) => r.json())
                .then((res) => {
                  if (!res || !res.success) throw new Error();
                  // Remove locally and re-render
                  commentItems.splice(idx, 1);
                  renderComments();
                })
                .catch(() => {
                  if (window.stickyFeedback) {
                    window.stickyFeedback.showNotification(
                      "Failed to delete comment",
                      "error"
                    );
                  }
                })
                .finally(() => {
                  if (btnLoader)
                    window.stickyFeedback?.hideLoadingIndicator(btnLoader);
                });
            });
            row.appendChild(delBtn);
          }
        } catch (_) {}
        commentsList.appendChild(row);
      });
    } catch (_) {}
  }

  commentsToggle.addEventListener("click", function (e) {
    e.stopPropagation();
    const isOpen = commentsPanel.classList.contains("sticky-open");
    if (isOpen) {
      commentsPanel.classList.remove("sticky-open");
    } else {
      commentsPanel.classList.add("sticky-open");
      // Scroll to bottom when opening (after layout applies)
      try {
        requestAnimationFrame(() => {
          commentsPanel.scrollTop = commentsPanel.scrollHeight;
        });
      } catch (_) {
        // Fallback without rAF
        commentsPanel.scrollTop = commentsPanel.scrollHeight;
      }
    }
  });

  commentsAddBtn.addEventListener("click", function () {
    const val = (commentsInput.value || "").trim();
    if (!val) return;
    if (!wrapper.dataset.noteId) {
      // Save the note first to obtain an ID
      const loader =
        window.stickyFeedback?.showLoadingIndicator(commentsAddBtn);
      saveNoteState()
        .then(() => {
          if (wrapper.dataset.noteId) {
            // Retry adding comment after save
            commentsAddBtn.click();
          }
        })
        .finally(() => {
          if (loader) window.stickyFeedback?.hideLoadingIndicator(loader);
        });
      return;
    }
    const loader = window.stickyFeedback?.showLoadingIndicator(commentsAddBtn);
    fetch(my_ajax_object.ajax_url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "sticky_add_comment",
        nonce: my_ajax_object.nonce,
        note_id: String(wrapper.dataset.noteId),
        content: val.slice(0, 500),
      }),
    })
      .then((r) => r.json())
      .then((res) => {
        if (!res || !res.success) throw new Error();
        // Append new comment locally
        const newItem = {
          user_id: (my_ajax_object && my_ajax_object.current_user_id) || 0,
          user_email:
            (my_ajax_object && my_ajax_object.current_user_email) || "",
          first_name:
            (my_ajax_object && my_ajax_object.current_user_first_name) || "",
          content: val.slice(0, 500),
          created_at:
            (res.data && res.data.latest && res.data.latest.created_at) ||
            new Date().toISOString(),
        };
        commentItems.push(newItem);
        commentsInput.value = "";
        renderComments();
        // Ensure icon is visible after first comment
        commentsToggle.classList.remove("sticky-hidden");
      })
      .catch(() => {
        if (window.stickyFeedback) {
          window.stickyFeedback.showNotification(
            "Failed to add comment",
            "error"
          );
        }
      })
      .finally(() => {
        if (loader) window.stickyFeedback?.hideLoadingIndicator(loader);
      });
  });

  // Submit comment on Enter (without Shift)
  commentsInput.addEventListener("keydown", function (e) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      commentsAddBtn.click();
    }
  });

  // Show default (medium) priority dot immediately on injection
  wrapper.dataset.priority = String(priorityValue);
  updatePriorityBadge();

  function updatePriorityBadge() {
    const p = parseInt(
      (wrapper.dataset && wrapper.dataset.priority) || priorityValue || 2,
      10
    );
    if (!priorityBadge) return;
    let dotClass;
    if (p === 1) {
      dotClass = "priority-dot-low";
    } else if (p === 2) {
      dotClass = "priority-dot-medium";
    } else {
      dotClass = "priority-dot-high";
    }
    priorityBadge.classList.remove("sticky-hidden");
    priorityBadge.classList.add("sticky-flex");
    priorityBadge.innerHTML =
      '<span class="sticky-priority-dot ' + dotClass + '"></span>';
  }

  actionsBtn.addEventListener("click", (e) => {
    e.stopPropagation();

    // Close any existing priority popover when opening actions menu
    const existingPriority = document.getElementById("sticky-priority-popover");
    if (existingPriority) {
      existingPriority.remove();
    }

    const isNowCompleted =
      wrapper.dataset.completed === "1" || isCompleted === 1;
    const completeItem = menu.querySelector('[data-action="complete"]');
    const uncompleteItem = menu.querySelector('[data-action="uncomplete"]');
    if (completeItem && uncompleteItem) {
      if (isNowCompleted) {
        completeItem.classList.add("sticky-hidden");
        completeItem.classList.remove("sticky-block");
        uncompleteItem.classList.remove("sticky-hidden");
        uncompleteItem.classList.add("sticky-block");
      } else {
        completeItem.classList.remove("sticky-hidden");
        completeItem.classList.add("sticky-block");
        uncompleteItem.classList.add("sticky-hidden");
        uncompleteItem.classList.remove("sticky-block");
      }
    }
    if (menu.classList.contains("sticky-hidden")) {
      menu.classList.remove("sticky-hidden");
      menu.classList.add("sticky-block");
    } else {
      menu.classList.add("sticky-hidden");
      menu.classList.remove("sticky-block");
    }
  });
  document.addEventListener("click", () => {
    menu.classList.add("sticky-hidden");
    menu.classList.remove("sticky-block");
  });
  menu.addEventListener("click", (e) => {
    const item = e.target.closest(".sticky-actions-item");
    if (!item) return;
    const action = item.getAttribute("data-action");
    if (action === "assign") {
      if (typeof window.openStickyAssignModal === "function") {
        window.openStickyAssignModal({
          initialValue: assignedToValue || "",
          users: (window.my_ajax_object && window.my_ajax_object.users) || [],
          onConfirm: (finalAssignee) => {
            assignedToValue = (finalAssignee || "").trim();
            wrapper.dataset.assignedTo = assignedToValue;
            const maybeLoader =
              window.stickyFeedback?.showLoadingIndicator(actionsBtn);
            saveNoteState()
              .then(() => {
                if (window.stickyFeedback) {
                  window.stickyFeedback.showNotification(
                    "Assignee updated",
                    "success",
                    1500
                  );
                }
              })
              .catch(() => {
                if (window.stickyFeedback) {
                  window.stickyFeedback.showNotification(
                    "Failed to assign",
                    "error"
                  );
                }
                saveButton.classList.remove("sticky-save-hidden");
                saveButton.classList.add("sticky-save-visible");
              })
              .finally(() => {
                if (maybeLoader)
                  window.stickyFeedback?.hideLoadingIndicator(maybeLoader);
              });
          },
        });
      }
    } else if (action === "priority") {
      const existing = document.getElementById("sticky-priority-popover");
      if (existing) existing.remove();
      const pop = document.createElement("div");
      pop.id = "sticky-priority-popover";
      pop.className = "sticky-priority-popup";
      pop.innerHTML =
        '<label class="sticky-priority-label">Priority</label>' +
        '<div class="sticky-priority-choices">' +
        '<button type="button" class="sticky-priority-choice" data-value="1">' +
        '<span class="sticky-priority-dot priority-dot-low"></span>' +
        '<span class="sticky-priority-choice-label">Low</span>' +
        "</button>" +
        '<button type="button" class="sticky-priority-choice" data-value="2">' +
        '<span class="sticky-priority-dot priority-dot-medium"></span>' +
        '<span class="sticky-priority-choice-label">Medium</span>' +
        "</button>" +
        '<button type="button" class="sticky-priority-choice" data-value="3">' +
        '<span class="sticky-priority-dot priority-dot-high"></span>' +
        '<span class="sticky-priority-choice-label">High</span>' +
        "</button>" +
        "</div>";
      actions.appendChild(pop);
      const currentPriority = parseInt(
        (wrapper.dataset && wrapper.dataset.priority) || priorityValue || 2,
        10
      );
      const choiceButtons = Array.from(
        pop.querySelectorAll(".sticky-priority-choice")
      );
      choiceButtons.forEach((btn) => {
        const val = parseInt(btn.getAttribute("data-value"), 10);
        if (val === (isNaN(currentPriority) ? 2 : currentPriority)) {
          btn.classList.add("is-active");
        }
      });

      function applyPriorityAndSave(val) {
        const normalized = val >= 1 && val <= 3 ? val : 2;
        priorityValue = normalized;
        wrapper.dataset.priority = String(priorityValue);
        updatePriorityBadge();
        const maybeLoader =
          window.stickyFeedback?.showLoadingIndicator(actionsBtn);
        saveNoteState()
          .then(() => {
            if (window.stickyFeedback) {
              window.stickyFeedback.showNotification(
                "Priority updated",
                "success",
                1200
              );
            }
          })
          .catch(() => {
            if (window.stickyFeedback) {
              window.stickyFeedback.showNotification(
                "Failed to update priority",
                "error"
              );
            }
            saveButton.classList.remove("sticky-save-hidden");
            saveButton.classList.add("sticky-save-visible");
          })
          .finally(() => {
            if (maybeLoader)
              window.stickyFeedback?.hideLoadingIndicator(maybeLoader);
            document.removeEventListener("click", closePriorityPopover);
            pop.remove();
          });
      }

      choiceButtons.forEach((btn) => {
        btn.addEventListener("click", (ev) => {
          ev.preventDefault();
          const val = parseInt(btn.getAttribute("data-value"), 10);
          choiceButtons.forEach((b) => b.classList.remove("is-active"));
          btn.classList.add("is-active");
          applyPriorityAndSave(val);
        });
      });

      // Add click-outside functionality to close the priority popover
      const closePriorityPopover = (e) => {
        if (!pop.contains(e.target) && !actionsBtn.contains(e.target)) {
          pop.remove();
          document.removeEventListener("click", closePriorityPopover);
        }
      };
      // Add the event listener on the next tick to prevent immediate closing
      setTimeout(() => {
        document.addEventListener("click", closePriorityPopover);
      }, 0);

      // Apply button removed in favor of instant save on option click
    } else if (action === "complete") {
      isCompleted = 1;
      wrapper.dataset.completed = "1";
      if (
        window.localStorage &&
        localStorage.getItem("sticky_show_completed") === "1"
      ) {
        wrapper.classList.remove("sticky-hidden");
        wrapper.classList.add("sticky-block");
      } else {
        wrapper.classList.add("sticky-hidden");
        wrapper.classList.remove("sticky-block");
      }
      if (window.updateBubbleCount) {
        window.updateBubbleCount();
      }
      const maybeLoader =
        window.stickyFeedback?.showLoadingIndicator(actionsBtn);
      saveNoteState()
        .then(() => {
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification(
              "Marked complete",
              "success",
              1500
            );
          }
        })
        .catch(() => {
          wrapper.classList.remove("sticky-hidden");
          wrapper.classList.add("sticky-block");
          wrapper.dataset.completed = "0";
          isCompleted = 0;
          if (window.updateBubbleCount) {
            window.updateBubbleCount();
          }
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification(
              "Failed to mark complete",
              "error"
            );
          }
          saveButton.classList.remove("sticky-save-hidden");
          saveButton.classList.add("sticky-save-visible");
        })
        .finally(() => {
          if (maybeLoader)
            window.stickyFeedback?.hideLoadingIndicator(maybeLoader);
        });
    } else if (action === "uncomplete") {
      isCompleted = 0;
      wrapper.dataset.completed = "0";
      wrapper.style.display = "block";
      if (window.updateBubbleCount) {
        window.updateBubbleCount();
      }
      const maybeLoader =
        window.stickyFeedback?.showLoadingIndicator(actionsBtn);
      saveNoteState()
        .then(() => {
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification(
              "Marked incomplete",
              "success",
              1500
            );
          }
        })
        .catch(() => {
          wrapper.dataset.completed = "1";
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification("Failed to unmark", "error");
          }
          saveButton.classList.remove("sticky-save-hidden");
          saveButton.classList.add("sticky-save-visible");
        })
        .finally(() => {
          if (maybeLoader)
            window.stickyFeedback?.hideLoadingIndicator(maybeLoader);
        });
    } else if (action === "add-comment") {
      // Open comments panel and focus input
      commentsPanel.classList.add("sticky-open");
      try {
        commentsInput.focus();
      } catch (_) {}
    } else if (action === "add-images") {
      try {
        hiddenFile.click();
      } catch (_) {}
    }
    menu.style.display = "none";
  });

  // Keep Save button hidden initially; it will show on user actions

  if (typeof window.setupStickyNoteCloseHandler === "function") {
    window.setupStickyNoteCloseHandler(wrapper, close);
  } else {
    close.addEventListener("click", () => {
      // Hide the save button when close button is clicked
      const saveButton = wrapper.querySelector(".sticky-comment-save");
      if (saveButton) {
        saveButton.classList.add("sticky-save-hidden");
        saveButton.classList.remove("sticky-save-visible");
      }
      wrapper.remove();
      currentNoteCount = Math.max(0, (Number(currentNoteCount) || 0) - 1);
      try {
        window.currentNoteCount = Math.max(
          0,
          Number(window.currentNoteCount || 0) - 1
        );
      } catch (_) {}
      if (window.updateBubbleCount) {
        window.updateBubbleCount();
      }
    });
  }

  let offsetX = 0,
    offsetY = 0,
    isDragging = false;

  handle.addEventListener("mousedown", (e) => {
    if (
      (e.target &&
        e.target.closest &&
        e.target.closest(".sticky-actions-menu")) ||
      (e.target &&
        e.target.closest &&
        e.target.closest("#sticky-priority-popover"))
    ) {
      return;
    }
    CreateStickyUtils.focusNote(wrapper);
    isDragging = true;
    offsetX = e.clientX - wrapper.getBoundingClientRect().left;
    offsetY = e.clientY - wrapper.getBoundingClientRect().top;
    e.preventDefault();
  });

  document.addEventListener("mousemove", (e) => {
    if (!isDragging) return;

    let newLeft = e.pageX - offsetX;
    let newTop = e.pageY - offsetY;

    newLeft = Math.min(Math.max(newLeft, 0), docWidth - wrapper.offsetWidth);
    newTop = Math.min(Math.max(newTop, 0), docHeight - wrapper.offsetHeight);

    wrapper.style.left = newLeft + "px";
    wrapper.style.top = newTop + "px";
  });

  document.addEventListener("mouseup", () => {
    if (!isDragging) return;
    isDragging = false;
    saveButton.classList.remove("sticky-save-hidden");
    saveButton.classList.add("sticky-save-visible");
    // Persist new position after drag ends if note already exists
    if (wrapper && wrapper.dataset && wrapper.dataset.noteId) {
      const loader = window.stickyFeedback?.showLoadingIndicator(saveButton);
      saveNoteState()
        .then(() => {
          // Keep the Save button visible after drag so users can save again or see status
          saveButton.classList.remove("sticky-save-hidden");
          saveButton.classList.add("sticky-save-visible");
        })
        .catch(() => {
          // Keep the save button visible so user can retry manually
          saveButton.classList.remove("sticky-save-hidden");
          saveButton.classList.add("sticky-save-visible");
        })
        .finally(() => {
          if (loader) window.stickyFeedback?.hideLoadingIndicator(loader);
        });
    }
  });

  textArea.addEventListener("focus", () => {
    CreateStickyUtils.focusNote(wrapper);
    saveButton.classList.remove("sticky-save-hidden");
    saveButton.classList.add("sticky-save-visible");
    updateCharUI();
  });

  // Show Save button whenever content changes and enforce limit
  textArea.addEventListener("input", () => {
    if (textArea.value.length > MAX_CHARS) {
      textArea.value = textArea.value.slice(0, MAX_CHARS);
    }
    updateCharUI();
    saveButton.classList.remove("sticky-save-hidden");
    saveButton.classList.add("sticky-save-visible");
  });

  saveButton.addEventListener("click", () => {
    // Close comments panel with animation when saving
    try {
      if (commentsPanel && commentsPanel.classList) {
        commentsPanel.classList.remove("sticky-open");
      }
    } catch (_) {}

    const pendingVal =
      commentsInput && commentsInput.value
        ? String(commentsInput.value).trim()
        : "";

    const loader = window.stickyFeedback?.showLoadingIndicator(saveButton);

    function finalizeSave() {
      return saveNoteState(true).finally(() => {
        saveButton.classList.add("sticky-save-hidden");
        saveButton.classList.remove("sticky-save-visible");
        if (loader) window.stickyFeedback?.hideLoadingIndicator(loader);
      });
    }

    // If there is no pending comment text, just save
    if (!pendingVal) {
      finalizeSave();
      return;
    }

    // Ensure we have a note ID before adding the comment
    const ensureNoteId =
      wrapper && wrapper.dataset && wrapper.dataset.noteId
        ? Promise.resolve()
        : saveNoteState();

    ensureNoteId
      .then(() => {
        // Re-check ID presence
        if (!wrapper || !wrapper.dataset || !wrapper.dataset.noteId) {
          return Promise.resolve();
        }
        return fetch(my_ajax_object.ajax_url, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams({
            action: "sticky_add_comment",
            nonce: my_ajax_object.nonce,
            note_id: String(wrapper.dataset.noteId),
            content: pendingVal.slice(0, 500),
          }),
        })
          .then((r) => r.json())
          .then((res) => {
            if (!res || !res.success) return;
            // Append new comment locally (mirror logic from Add button)
            const newItem = {
              user_id: (my_ajax_object && my_ajax_object.current_user_id) || 0,
              user_email:
                (my_ajax_object && my_ajax_object.current_user_email) || "",
              content: pendingVal.slice(0, 500),
              created_at:
                (res.data && res.data.latest && res.data.latest.created_at) ||
                new Date().toISOString(),
            };
            commentItems.push(newItem);
            commentsInput.value = "";
            renderComments();
            // Ensure icon is visible after first comment
            commentsToggle.classList.remove("sticky-hidden");
          })
          .catch(() => {
            // Non-fatal; proceed to final save
          });
      })
      .finally(() => {
        finalizeSave();
      });
  });

  // Images modal viewer
  function openStickyImagesModal() {
    if (!Array.isArray(imageItems) || imageItems.length === 0) return;
    const overlay = document.createElement("div");
    overlay.className = "sticky-images-modal";
    const content = document.createElement("div");
    content.className = "sticky-images-modal-content";
    const closeBtn = document.createElement("button");
    closeBtn.className = "sticky-images-modal-close";
    closeBtn.innerHTML = "&times;";
    const deleteBtn = document.createElement("button");
    deleteBtn.className = "sticky-images-modal-delete";
    deleteBtn.title = "Delete image";
    deleteBtn.textContent = "Delete";
    const actionsBar = document.createElement("div");
    actionsBar.className = "sticky-images-modal-actions";
    const imgEl = document.createElement("img");
    imgEl.className = "sticky-images-modal-image";
    let index = 0;
    const countBadge = document.createElement("div");
    countBadge.className = "sticky-images-count";
    function renderSlide() {
      const cur = imageItems[index];
      imgEl.src = (cur && (cur.url || cur.thumb_url)) || "";
      try {
        countBadge.textContent = `${index + 1} / ${imageItems.length}`;
      } catch (_) {}
    }
    const prevBtn = document.createElement("button");
    prevBtn.className = "sticky-images-nav sticky-images-prev";
    prevBtn.textContent = "\u2039";
    const nextBtn = document.createElement("button");
    nextBtn.className = "sticky-images-nav sticky-images-next";
    nextBtn.textContent = "\u203A";
    prevBtn.addEventListener("click", (ev) => {
      ev.stopPropagation();
      index = (index - 1 + imageItems.length) % imageItems.length;
      renderSlide();
    });
    nextBtn.addEventListener("click", (ev) => {
      ev.stopPropagation();
      index = (index + 1) % imageItems.length;
      renderSlide();
    });
    function closeOverlay() {
      try {
        document.removeEventListener("keydown", onKeydown);
      } catch (_) {}
      try {
        overlay.remove();
      } catch (_) {}
    }
    function deleteCurrentImage() {
      const current = imageItems[index];
      if (!current || !current.id) return;
      const attachmentId = Number(current.id);
      const loader = window.stickyFeedback?.showLoadingIndicator(deleteBtn);
      fetch(my_ajax_object.ajax_url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          action: "sticky_delete_note_image",
          attachment_id: String(attachmentId),
          nonce: my_ajax_object.nonce,
        }),
      })
        .then((r) => r.json())
        .then((res) => {
          if (!res || !res.success) {
            throw new Error(
              res && (res.error || res.data?.message)
                ? res.error || res.data?.message
                : "Failed"
            );
          }
          // Remove from local list and re-render
          imageItems = imageItems.filter(
            (it) => Number(it.id) !== attachmentId
          );
          if (imageItems.length === 0) {
            renderImages(); // Update the indicator to hide it
            closeOverlay();
          } else {
            index = index % imageItems.length;
            renderImages();
            renderSlide();
          }
          // Mark note as needing save with updated images array
          saveButton.classList.remove("sticky-save-hidden");
          saveButton.classList.add("sticky-save-visible");
        })
        .catch(() => {
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification(
              "Failed to delete image",
              "error"
            );
          }
        })
        .finally(() => {
          if (loader) window.stickyFeedback?.hideLoadingIndicator(loader);
        });
    }
    const onKeydown = (ev) => {
      if (ev && ev.key === "ArrowLeft") {
        ev.preventDefault();
        index = (index - 1 + imageItems.length) % imageItems.length;
        renderSlide();
      } else if (ev && ev.key === "ArrowRight") {
        ev.preventDefault();
        index = (index + 1) % imageItems.length;
        renderSlide();
      }
    };
    document.addEventListener("keydown", onKeydown);
    closeBtn.addEventListener("click", (ev) => {
      ev.stopPropagation();
      closeOverlay();
    });
    deleteBtn.addEventListener("click", (ev) => {
      ev.stopPropagation();
      deleteCurrentImage();
    });
    overlay.addEventListener("click", (ev) => {
      if (ev.target === overlay) closeOverlay();
    });
    actionsBar.appendChild(deleteBtn);
    actionsBar.appendChild(closeBtn);
    content.appendChild(actionsBar);
    content.appendChild(countBadge);
    content.appendChild(imgEl);
    if (imageItems.length > 1) {
      content.appendChild(prevBtn);
      content.appendChild(nextBtn);
    }
    overlay.appendChild(content);
    document.body.appendChild(overlay);
    renderSlide();
  }

  // Open modal when clicking the indicator
  imagesIndicator.addEventListener("click", (e) => {
    e.stopPropagation();
    openStickyImagesModal();
  });

  let notifiedOnce = false;

  function saveNoteState(notify) {
    const left = parseFloat(wrapper.style.left);
    const top = parseFloat(wrapper.style.top);

    const leftPercent = (left / docWidth) * 100;
    const topPercent = (top / docHeight) * 100;

    const postId = Number(my_ajax_object && my_ajax_object.post_id);
    const pageUrl =
      (my_ajax_object && my_ajax_object.page_url) || window.location.href;
    const completedFlag =
      wrapper && wrapper.dataset && wrapper.dataset.completed === "1" ? 1 : 0;

    const saveParams = {
      action: "sticky_comment",
      x: leftPercent.toFixed(3),
      y: topPercent.toFixed(3),
      content: (textArea.value || "").slice(0, MAX_CHARS),
      element_path: elementPath || "",
      is_collapsed: 0,
      nonce: my_ajax_object.nonce,
      post_id: postId ? String(postId) : "0",
      page_url: pageUrl,
      assigned_to: assignedToValue || "",
      priority: String(
        wrapper && wrapper.dataset && wrapper.dataset.priority
          ? wrapper.dataset.priority
          : priorityValue || 2
      ),
      is_completed: completedFlag,
      is_done: completedFlag,
      note_id: wrapper.dataset.noteId || "",
      title:
        wrapper.dataset && wrapper.dataset.title ? wrapper.dataset.title : "",
      images: JSON.stringify(
        (imageItems || [])
          .map((it) => Number(it.id))
          .filter((n) => Number.isFinite(n))
      ),
      device:
        (wrapper && wrapper.dataset && wrapper.dataset.device) ||
        getStickyDeviceType(),
    };
    if (notify) {
      saveParams.notify = "1";
      saveParams.is_new = notifiedOnce ? "0" : "1";
      notifiedOnce = true;
    }
    if (my_ajax_object.is_guest === 1 && my_ajax_object.guest_token && my_ajax_object.guest_id) {
      saveParams.guest_token = my_ajax_object.guest_token;
      saveParams.guest_id = my_ajax_object.guest_id;
    }
    return fetch(my_ajax_object.ajax_url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams(saveParams),
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          if (data.data?.note_id) {
            wrapper.dataset.noteId = data.data.note_id;
          }
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification(
              "Note saved successfully!",
              "success",
              2000
            );
          }
          if (data.data?.email_sent === "sent" && window.stickyFeedback) {
            setTimeout(() => {
              window.stickyFeedback.showNotification(
                "Email notification sent",
                "success",
                3000
              );
            }, 500);
          } else if (data.data?.email_sent === "failed" && window.stickyFeedback) {
            const reason = data.data?.email_error || "Email notification failed to send";
            setTimeout(() => {
              window.stickyFeedback.showNotification(
                reason,
                "error",
                6000
              );
            }, 500);
          }
        } else if (data.error || data.data?.message) {
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification(
              data.error || data.data?.message,
              "error"
            );
          }
        }
      })
      .catch((error) => {
        if (window.stickyFeedback) {
          window.stickyFeedback.showNotification(
            "Failed to save note",
            "error"
          );
        }
      });
  }

  // Allow external population of images (used by initial fetch)
  wrapper.setNoteImages = function (ids, urls) {
    try {
      const idArray = Array.isArray(ids)
        ? ids.map((v) => Number(v)).filter((n) => Number.isFinite(n))
        : [];
      const urlArray = Array.isArray(urls) ? urls : [];
      const items = [];
      const maxLen = Math.max(idArray.length, urlArray.length);
      for (let i = 0; i < maxLen; i++) {
        const id = idArray[i];
        const url = urlArray[i];
        if (!url || typeof url !== "string" || url.trim() === "") continue;
        items.push({ id, thumb_url: url, url: url });
      }
      imageItems = items;
      renderImages();
    } catch (_) {}
  };

  // Allow external population of comments (used by initial fetch)
  wrapper.setComments = function (arr) {
    try {
      const items = Array.isArray(arr) ? arr : [];
      commentItems = items
        .map(function (c) {
          return {
            user_id: Number(c && c.user_id) || 0,
            user_email: c && c.user_email ? String(c.user_email) : "",
            first_name: c && c.first_name ? String(c.first_name) : "",
            content: c && c.content ? String(c.content) : "",
            created_at: c && c.created_at ? String(c.created_at) : "",
          };
        })
        .filter(function (c) {
          return c.content && c.content.trim() !== "";
        });
      renderComments();
    } catch (_) {}
  };

  return wrapper;
}

window.StickyComment = window.StickyComment || {};
window.StickyComment.createStickyNote = createStickyNote;
window.createStickyNote = createStickyNote;

if (typeof window.openStickyAssignModal !== "function") {
  window.openStickyAssignModal = function ({
    initialValue = "",
    users = [],
    onConfirm = () => {},
  } = {}) {
    let overlay = document.getElementById("sticky-assign-overlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "sticky-assign-overlay";
      overlay.className = "sticky-modal-overlay";

      const modal = document.createElement("div");
      modal.id = "sticky-assign-modal";
      modal.className = "sticky-modal-content";
      modal.innerHTML =
        '<div class="sticky-modal-assign-header">' +
        '<div class="sticky-assign-title">Assign to</div>' +
        '<button type="button" id="sticky-assign-close" class="sticky-assign-close">×</button>' +
        "</div>" +
        '<div class="sticky-assign-body">' +
        '<div class="sticky-assign-input-container">' +
        '<input id="sticky-assign-input" class="sticky-assign-input" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" name="sticky_assign_avoid_autofill" placeholder="Assign to..." />' +
        '<div id="sticky-assign-results" class="sticky-assign-results"></div>' +
        "</div>" +
        "</div>" +
        '<div class="sticky-assign-buttons">' +
        '<button type="button" id="sticky-assign-cancel" class="sticky-assign-cancel">Cancel</button>' +
        '<button type="button" id="sticky-assign-confirm" class="sticky-assign-button">ASSIGN</button>' +
        "</div>";

      overlay.appendChild(modal);
      document.body.appendChild(overlay);

      const closeBtn = modal.querySelector("#sticky-assign-close");
      const cancelBtn = modal.querySelector("#sticky-assign-cancel");
      function hideAndRemoveOverlay() {
        overlay.classList.add("sticky-modal-overlay-hidden");
        overlay.classList.remove("sticky-modal-overlay-visible");
        try {
          overlay.remove();
        } catch (_) {}
      }
      [closeBtn, cancelBtn].forEach(
        (btn) => btn && btn.addEventListener("click", hideAndRemoveOverlay)
      );
      overlay.addEventListener("click", (ev) => {
        if (ev.target === overlay) {
          hideAndRemoveOverlay();
        }
      });
    }

    const input = overlay.querySelector("#sticky-assign-input");
    const resultsBox = overlay.querySelector("#sticky-assign-results");
    const confirmBtn = overlay.querySelector("#sticky-assign-confirm");

    input.value = initialValue || "";

    input.addEventListener("input", () => {
      if (typeof fetchUserSuggestionsDropdown === "function") {
        fetchUserSuggestionsDropdown(input, resultsBox);
      }
    });

    input.addEventListener("blur", () => {
      setTimeout(() => {
        if (resultsBox) resultsBox.classList.add("sticky-hidden");
        resultsBox.classList.remove("sticky-block");
      }, 150);
    });

    resultsBox.addEventListener("click", (ev) => {
      const item = ev.target.closest("[data-login]");
      if (!item) return;
      input.value = item.textContent.trim();
      resultsBox.classList.add("sticky-hidden");
      resultsBox.classList.remove("sticky-block");
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        confirmBtn.click();
      }
    });

    confirmBtn.onclick = () => {
      let freeText = input.value ? String(input.value).trim() : "";
      let finalVal = "";
      if (freeText) {
        // Check if the input exactly matches a displayed email
        const known = (Array.isArray(users) ? users : []).find(function (u) {
          return (
            u &&
            typeof u.login === "string" &&
            u.email.toLowerCase() === freeText.toLowerCase()
          );
        });
        if (known) {
          finalVal = `@${known.login}`;
        } else if (/^@/.test(freeText)) {
          finalVal = freeText;
        } else {
          finalVal = freeText;
        }
      }

      onConfirm(finalVal);
      overlay.classList.add("sticky-modal-overlay-hidden");
      overlay.classList.remove("sticky-modal-overlay-visible");
      // Ensure the modal is fully closed and removed after assignment
      try {
        overlay.remove();
      } catch (_) {}
    };

    overlay.classList.remove("sticky-modal-overlay-hidden");
    overlay.classList.add("sticky-modal-overlay-visible");
    setTimeout(() => input.focus(), 0);
  };
}

let stickyUserSuggestTimer;
function fetchUserSuggestionsDropdown(input, resultsBox) {
  const q = input.value.trim();
  clearTimeout(stickyUserSuggestTimer);
  // Hide results for queries shorter than 3 characters
  if (q.length < 3) {
    if (resultsBox) resultsBox.classList.add("sticky-hidden");
    resultsBox.classList.remove("sticky-block");
    if (resultsBox) resultsBox.innerHTML = "";
    // Remove spinner if present
    input.classList.remove("sticky-assign-input-loading");
    return;
  }
  stickyUserSuggestTimer = setTimeout(() => {
    // Show spinner inside input field
    input.classList.add("sticky-assign-input-loading");

    fetch(my_ajax_object.ajax_url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "search_sticky_users",
        nonce: my_ajax_object.nonce,
        q,
      }),
    })
      .then((r) => r.json())
      .then((res) => {
        if (!res.success || !Array.isArray(res.data)) {
          resultsBox.classList.add("sticky-hidden");
          resultsBox.classList.remove("sticky-block");
          resultsBox.innerHTML = "";
          return;
        }
        if (res.data.length === 0) {
          resultsBox.innerHTML =
            '<div class="sticky-assign-no-results">No users found</div>';
          resultsBox.classList.remove("sticky-hidden");
          resultsBox.classList.add("sticky-block");
          return;
        }
        resultsBox.innerHTML = res.data
          .map((u) => {
            return `<div data-login="${u.login}" class="sticky-assign-result-item">${u.email}</div>`;
          })
          .join("");
        resultsBox.classList.remove("sticky-hidden");
        resultsBox.classList.add("sticky-block");
      })
      .catch(() => {
        resultsBox.innerHTML =
          '<div class="sticky-assign-error">Search failed</div>';
        resultsBox.classList.remove("sticky-hidden");
        resultsBox.classList.add("sticky-block");
      })
      .finally(() => {
        // Hide spinner
        input.classList.remove("sticky-assign-input-loading");
      });
  }, 200);
}
