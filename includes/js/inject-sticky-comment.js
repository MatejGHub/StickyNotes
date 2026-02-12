// Utility functions for optimization
const StickyUtils = {
  // Get maximum notes limit
  getMaxNotes() {
    return (
      Number(window.my_ajax_object && window.my_ajax_object.max_notes) || 10
    );
  },

  // Count active (non-completed) notes
  countActiveNotes() {
    return Array.from(document.querySelectorAll(".sticky-note")).filter(
      (n) => n.dataset.completed !== "1"
    ).length;
  },

  // Show notification if feedback system is available
  showNotification(message, type = "info", duration = 3000) {
    if (window.stickyFeedback) {
      window.stickyFeedback.showNotification(message, type, duration);
    } else if (type === "error" || type === "warning") {
      alert(message);
    }
  },

  // Manage cursor states
  setCursorState(state) {
    document.body.classList.remove(
      "sticky-cursor-crosshair",
      "sticky-cursor-default"
    );
    if (state === "crosshair") {
      document.body.classList.add("sticky-cursor-crosshair");
    } else {
      document.body.classList.add("sticky-cursor-default");
    }
  },

  // Check if note limit is reached
  isLimitReached() {
    return this.countActiveNotes() >= this.getMaxNotes();
  },
};

// Floating Bubble Interface for Sticky Notes
document.addEventListener("DOMContentLoaded", function () {
  // If user cannot edit notes, do not render bubble UI
  if (!window.my_ajax_object || Number(window.my_ajax_object.can_edit) !== 1) {
    return;
  }
  // Default: do NOT show completed notes on refresh
  try {
    window.localStorage.setItem("sticky_show_completed", "0");
  } catch (_) {}

  // Reset completed-load flag each page load
  try {
    window.__stickyCompletedLoaded = false;
  } catch (_) {}

  // Create floating bubble interface
  createFloatingBubble();
});

function createFloatingBubble() {
  // Create bubble
  const bubble = document.createElement("div");
  bubble.className = "sticky-bubble";
  bubble.innerHTML =
    '<div class="sticky-bubble-icon">📝</div><div class="sticky-bubble-count sticky-hidden">0</div>';

  // Create menu
  const menu = document.createElement("div");
  menu.className = "sticky-bubble-menu";
  menu.innerHTML = `
    <div class="sticky-menu-item" data-action="add-note">
      <span class="sticky-menu-item-icon">➕</span>
      <span>Add New Note</span>
    </div>
    <div class="sticky-menu-item" data-action="view-notes">
      <span class="sticky-menu-item-icon">📋</span>
      <span>View All Notes</span>
    </div>
    <div class="sticky-menu-item" data-action="toggle-notes">
      <span class="sticky-menu-item-icon">👁️</span>
      <span>Toggle Notes</span>
    </div>
    <div class="sticky-menu-item" data-action="toggle-completed">
      <span class="sticky-menu-item-icon">✅</span>
      <span>Toggle Completed</span>
    </div>
  `;

  // Add to DOM
  document.body.appendChild(bubble);
  document.body.appendChild(menu);

  // Update note count on bubble
  updateBubbleCount();

  // Track if shortcut is currently active to prevent rapid repeated executions
  let shortcutActive = false;

  // Keyboard shortcut for adding notes (Shift + Alt)
  document.addEventListener("keydown", (e) => {
    if (e.shiftKey && e.altKey && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      if (
        shortcutActive ||
        document.querySelector(".sticky-placement-overlay")
      ) {
        return;
      }
      shortcutActive = true;
      handleAddNote();
    }
  });

  // Reset shortcut flag when keys are released
  document.addEventListener("keyup", (e) => {
    if (!e.shiftKey || !e.altKey) {
      shortcutActive = false;
    }
  });

  // Bubble click handler
  let menuOpen = false;
  bubble.addEventListener("click", (e) => {
    e.stopPropagation();
    menuOpen = !menuOpen;
    menu.classList.toggle("show", menuOpen);
  });

  // Close menu when clicking outside
  document.addEventListener("click", () => {
    if (menuOpen) {
      menuOpen = false;
      menu.classList.remove("show");
    }
  });

  // Menu item handlers
  menu.addEventListener("click", (e) => {
    e.stopPropagation();
    const item = e.target.closest(".sticky-menu-item");
    if (!item) return;

    const action = item.dataset.action;

    switch (action) {
      case "add-note":
        handleAddNote();
        break;
      case "view-notes":
        handleViewNotes();
        break;
      case "toggle-notes":
        handleToggleNotes();
        break;
      case "toggle-completed":
        handleToggleCompleted();
        break;
    }

    // Close menu
    menuOpen = false;
    menu.classList.remove("show");
  });
}

function handleAddNote() {
  // Check note limit using utility function
  if (StickyUtils.isLimitReached()) {
    StickyUtils.showNotification(
      "You reached the limit of sticky notes.",
      "warning",
      2500
    );
    shortcutActive = false;
    return;
  }

  // Create crosshair cursor for placement
  StickyUtils.setCursorState("crosshair");

  // Show instruction
  StickyUtils.showNotification(
    "✨ Click anywhere to place your note",
    "info",
    4000
  );

  // Add overlay to help with placement (captures clicks so page below won't react)
  const overlay = document.createElement("div");
  overlay.className = "sticky-placement-overlay";
  document.body.appendChild(overlay);

  // One-time click handler for placement
  const placeNote = (event) => {
    if (StickyUtils.isLimitReached()) {
      StickyUtils.showNotification(
        "You reached the limit of sticky notes.",
        "warning",
        2500
      );
      StickyUtils.setCursorState("default");
      overlay.removeEventListener("click", placeNote);
      overlay.remove();
      shortcutActive = false;
      return;
    }
    // Prevent any default actions (e.g., link navigation) and stop propagation
    if (typeof event.preventDefault === "function") event.preventDefault();
    if (typeof event.stopImmediatePropagation === "function")
      event.stopImmediatePropagation();
    if (typeof event.stopPropagation === "function") event.stopPropagation();

    // Peek the underlying element at the click point
    overlay.classList.add("pointer-events-none");
    const underlying = document.elementFromPoint(event.clientX, event.clientY);
    overlay.classList.remove("pointer-events-none");

    // If click was on UI (bubble/menu), ignore and keep placement mode
    if (
      underlying &&
      (underlying.closest(".sticky-bubble") ||
        underlying.closest(".sticky-bubble-menu"))
    ) {
      return;
    }

    // Get element path
    function getElementPath(el) {
      const path = [];
      while (el && el !== document.body) {
        let selector = el.tagName.toLowerCase();
        if (el.id) selector += `#${el.id}`;
        else if (el.className)
          selector += `.${el.className.trim().split(/\s+/).join(".")}`;
        path.unshift(selector);
        el = el.parentElement;
      }
      let joined = path.join(" > ");
      if (joined.length > 1000) {
        joined = joined.slice(0, 1000);
      }
      return joined;
    }

    const elementPath = getElementPath(underlying || document.body);
    const xPercent = (event.pageX / document.documentElement.scrollWidth) * 100;
    const yPercent =
      (event.pageY / document.documentElement.scrollHeight) * 100;

    const wrapper = window.createStickyNote(xPercent, yPercent, elementPath);
    if (!wrapper) {
      // Limit reached or creation failed; exit gracefully
      document.body.classList.remove("sticky-cursor-crosshair");
      document.body.classList.add("sticky-cursor-default");
      overlay.removeEventListener("click", placeNote);
      overlay.remove();
      shortcutActive = false;
      return;
    }
    const textArea = wrapper.querySelector("textarea");
    const assigneeInput = wrapper.querySelector(".sticky-assignee");

    const postId = Number(my_ajax_object && my_ajax_object.post_id);
    const pageUrl =
      (my_ajax_object && my_ajax_object.page_url) || window.location.href;
    fetch(my_ajax_object.ajax_url, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: (function () {
        var p = {
          action: "sticky_comment",
          x: xPercent.toFixed(2),
          y: yPercent.toFixed(2),
          content: textArea.value,
          element_path: elementPath,
          nonce: my_ajax_object.nonce,
          post_id: postId ? String(postId) : "0",
          page_url: pageUrl,
          assigned_to:
            assigneeInput && assigneeInput.value ? assigneeInput.value : "",
          device: (wrapper && wrapper.dataset && wrapper.dataset.device) || "",
        };
        if (my_ajax_object.is_guest === 1 && my_ajax_object.guest_token && my_ajax_object.guest_id) {
          p.guest_token = my_ajax_object.guest_token;
          p.guest_id = my_ajax_object.guest_id;
        }
        return new URLSearchParams(p);
      })(),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success && data.data && data.data.note_id) {
          wrapper.dataset.noteId = data.data.note_id;
          StickyUtils.showNotification(
            "Note created successfully!",
            "success",
            2000
          );
          // Update bubble count
          if (window.updateBubbleCount) {
            window.updateBubbleCount();
          }
        } else {
          StickyUtils.showNotification(
            data.error || data.data?.message || "Failed to create note",
            "error"
          );
        }
      })
      .catch((error) => {
        StickyUtils.showNotification("Failed to create note", "error");
      });

    // Restore cursor and remove overlay and listener
    StickyUtils.setCursorState("default");
    overlay.removeEventListener("click", placeNote);
    overlay.remove();
    shortcutActive = false;
  };

  overlay.addEventListener("click", placeNote);

  // Allow cancelling placement with Escape
  const cancelWithEsc = (e) => {
    if (e.key === "Escape") {
      StickyUtils.setCursorState("default");
      overlay.removeEventListener("click", placeNote);
      document.removeEventListener("keydown", cancelWithEsc);
      overlay.remove();
      shortcutActive = false;
    }
  };
  document.addEventListener("keydown", cancelWithEsc);
}

function handleViewNotes() {
  showNotesModal();
}

function handleToggleNotes() {
  const notes = document.querySelectorAll(".sticky-note");
  const areNotesVisible =
    notes.length > 0 && !notes[0].classList.contains("sticky-hidden");

  // Check if completed notes should be shown
  const showCompleted = (function () {
    try {
      return window.localStorage.getItem("sticky_show_completed") === "1";
    } catch (_) {
      return false;
    }
  })();

  notes.forEach((note) => {
    const isCompleted = note.dataset.completed === "1";

    if (areNotesVisible) {
      // Hide all notes when toggling off
      note.classList.add("sticky-hidden");
      note.classList.remove("sticky-block");
    } else {
      // Show notes when toggling on, but respect completed visibility setting
      if (!isCompleted || showCompleted) {
        note.classList.remove("sticky-hidden");
        note.classList.add("sticky-block");
      } else {
        // Keep completed notes hidden if completed toggle is off
        note.classList.add("sticky-hidden");
        note.classList.remove("sticky-block");
      }
    }
  });

  // Update bubble count based on visibility
  if (window.updateBubbleCount) {
    window.updateBubbleCount();
  }

  if (window.stickyFeedback) {
    const message = areNotesVisible ? "Notes hidden" : "Notes shown";
    window.stickyFeedback.showNotification(message, "info", 2000);
  }
}

function handleToggleCompleted() {
  const desired = (function () {
    try {
      const cur = window.localStorage.getItem("sticky_show_completed");
      return cur === "1" ? "0" : "1";
    } catch (_) {
      return "1";
    }
  })();
  try {
    window.localStorage.setItem("sticky_show_completed", desired);
  } catch (_) {}

  const showCompleted = desired === "1";
  const notes = document.querySelectorAll(".sticky-note");
  notes.forEach((note) => {
    const isCompleted = note.dataset.completed === "1";
    if (isCompleted) {
      if (showCompleted) {
        note.classList.remove("sticky-hidden");
        note.classList.add("sticky-block");
      } else {
        note.classList.add("sticky-hidden");
        note.classList.remove("sticky-block");
      }
    }
  });

  if (window.updateBubbleCount) {
    window.updateBubbleCount();
  }

  if (window.stickyFeedback) {
    window.stickyFeedback.showNotification(
      showCompleted ? "Showing completed notes" : "Hiding completed notes",
      "info",
      1500
    );
  }

  // Update modal list if open
  if (typeof updateNotesModalContents === "function") {
    try {
      updateNotesModalContents();
    } catch (_) {}
  }

  // If showing completed, ensure data is loaded: refetch including completed ones
  if (showCompleted) {
    // Only fetch once per toggle to avoid duplicates
    if (!window.__stickyCompletedLoaded) {
      window.__stickyCompletedLoaded = true;
      const postId = Number(my_ajax_object && my_ajax_object.post_id);
      const pageUrl =
        (my_ajax_object && my_ajax_object.page_url) || window.location.href;
      fetch(my_ajax_object.ajax_url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: (function () {
          var p = {
            action: "get_sticky_notes_by_post_id",
            nonce: my_ajax_object.nonce,
            post_id: postId && !Number.isNaN(postId) ? String(postId) : "0",
            page_url: pageUrl,
            include_completed: "1",
          };
          if (my_ajax_object.is_guest === 1 && my_ajax_object.guest_token && my_ajax_object.guest_id) {
            p.guest_token = my_ajax_object.guest_token;
            p.guest_id = my_ajax_object.guest_id;
          }
          return new URLSearchParams(p);
        })(),
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.success || !Array.isArray(data.data)) return;
          // Render any missing completed notes (avoid duplicates by id)
          const existingIds = new Set(
            Array.from(document.querySelectorAll(".sticky-note"))
              .map((n) => Number(n.dataset.noteId))
              .filter(Boolean)
          );
          data.data.forEach((note) => {
            const isCompleted =
              Number(note.is_completed) === 1 || Number(note.is_done) === 1;
            if (!isCompleted) return; // only add completed ones
            if (existingIds.has(Number(note.id))) return;
            const x = parseFloat(note.position_x);
            const y = parseFloat(note.position_y);
            const wrapper = window.createStickyNote(x, y, "", {
              skipLimit: true,
              markCompleted: true,
            });
            if (!wrapper) return;
            const textarea = wrapper.querySelector("textarea");
            textarea.value = note.content;

            // Update counter UI manually without dispatching input event
            try {
              const counterWrap = wrapper.querySelector(
                ".sticky-char-counter-wrap"
              );
              if (counterWrap) {
                const counterCount =
                  counterWrap.querySelector(".sticky-char-count");
                if (counterCount) {
                  const MAX_CHARS = 500;
                  const len = Math.min(textarea.value.length, MAX_CHARS);
                  counterCount.textContent = String(len);
                }
              }
            } catch (_) {}

            wrapper.dataset.assignedTo = note.assigned_to || "";
            wrapper.dataset.noteId = note.id;
            wrapper.id = `sticky-note-${note.id}`;
            wrapper.dataset.completed = "1";
            wrapper.classList.remove("sticky-hidden");
            wrapper.classList.add("sticky-block");
          });
          if (window.updateBubbleCount) window.updateBubbleCount();
          // Refresh modal contents if open after loading completed notes
          if (typeof updateNotesModalContents === "function") {
            try {
              updateNotesModalContents();
            } catch (_) {}
          }
        })
        .catch(() => {});
    }
  }
}

function getShowCompletedFlag() {
  try {
    return window.localStorage.getItem("sticky_show_completed") === "1";
  } catch (_) {
    return true;
  }
}

// Get current device type based on screen width
function getCurrentDeviceType() {
  const width = window.innerWidth;
  if (width <= 576) {
    return "mobile";
  } else if (width >= 1024) {
    return "desktop";
  } else {
    return "tablet";
  }
}

function renderNotesList(modal, deviceFilter = null) {
  const notesList = modal.querySelector(".sticky-notes-list");
  const showCompleted = getShowCompletedFlag();
  const existingNotes = Array.from(document.querySelectorAll(".sticky-note"));
  const filteredNotes = existingNotes.filter(function (note) {
    const isCompleted = note.dataset.completed === "1";
    const noteDevice = note.dataset.device || "desktop"; // Default to desktop if no device set
    const showNote = showCompleted ? true : !isCompleted;

    // Filter by device if a filter is specified
    if (deviceFilter && noteDevice !== deviceFilter) {
      return false;
    }

    return showNote;
  });

  if (filteredNotes.length === 0) {
    notesList.innerHTML =
      '<div class="sticky-no-notes">No sticky notes on this page yet.</div>';
  } else {
    notesList.innerHTML = "";
    // Simple HTML escape to keep injected text safe
    function esc(str) {
      return String(str || "").replace(/[&<>"']/g, function (c) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        }[c];
      });
    }
    function toExcerpt(text) {
      const t = (text || "").replace(/\s+/g, " ").trim();
      return t.length > 120 ? t.slice(0, 117) + "…" : t || "Empty note";
    }
    function truncate10(text) {
      const t = String(text || "").trim();
      if (!t) return "Untitled";
      return t.length > 10 ? t.slice(0, 10) + "…" : t;
    }

    filteredNotes.forEach((note, index) => {
      const content = note.querySelector(".sticky-text").value || "";
      const gallery = note.querySelector(".sticky-images-list");
      const thumbs = gallery ? Array.from(gallery.querySelectorAll("img")) : [];
      const assigned =
        note.dataset.assignedTo ||
        (note.querySelector(".sticky-assignee") &&
          note.querySelector(".sticky-assignee").value) ||
        "";
      const isCompleted = note.dataset.completed === "1";
      const priority = parseInt(note.dataset.priority || "2", 10) || 2;
      const priorityLabel =
        priority === 1 ? "Low" : priority === 3 ? "High" : "Medium";
      const title = (note.dataset && note.dataset.title) || "";
      const titleDisplay = truncate10(title || `Note #${index + 1}`);

      const noteItem = document.createElement("div");
      noteItem.className = "sticky-list-row";
      noteItem.setAttribute("data-note-id", note.dataset.noteId || "");
      noteItem.innerHTML = `
        <div class="sticky-list-row-header">
          <span class="sticky-list-priority"><span class=\"sticky-priority-dot ${
            priority === 1
              ? "priority-dot-low"
              : priority === 3
              ? "priority-dot-high"
              : "priority-dot-medium"
          }\"></span></span>
          <span class="sticky-list-title">${esc(titleDisplay)}</span>
          <span class="sticky-list-excerpt">${esc(toExcerpt(content))}</span>
          <div class="sticky-note-item-actions">
            <button class="sticky-note-item-action" data-action="focus" data-note-id="${
              note.dataset.noteId || ""
            }">Focus</button>
            <button class="sticky-note-item-action" data-action="delete" data-note-id="${
              note.dataset.noteId || ""
            }">Delete</button>
          </div>
          <button class="sticky-row-toggle" aria-expanded="false"><span class="sticky-toggle-chevron"></span><span class="sticky-toggle-text">Expand</span></button>
        </div>
        <div class="sticky-list-row-body">
          <div class="sticky-list-content">${
            esc(content) || "<em>Empty note</em>"
          }</div>
          <div class="sticky-list-images">${
            thumbs
              .slice(0, 6)
              .map(
                (img) =>
                  `<img class=\"sticky-list-thumb\" src=\"${esc(
                    img.src
                  )}\" alt=\"\" />`
              )
              .join("") || ""
          }</div>
          <div class="sticky-list-meta">
            ${title ? `<div><strong>Title</strong>: ${esc(title)}</div>` : ""}
            ${assigned ? `<div><strong>Assigned</strong>: <span class="meta-assigned">${esc(assigned)}</span></div>` : ""}
            ${priority !== 2 ? `<div><strong>Priority</strong>: <span class="meta-priority">${priorityLabel}</span></div>` : ""}
            ${isCompleted ? `<div><strong>Status</strong>: <span class="meta-status">Completed</span></div>` : ""}
          </div>
        </div>
      `;
      notesList.appendChild(noteItem);
    });
  }
}

function updateNotesModalContents() {
  const modal = document.querySelector(".sticky-notes-modal");
  if (!modal || !modal.classList.contains("show")) return;

  // Get current device filter from active tab
  const activeTab = modal.querySelector(".sticky-tab-button.active");
  const deviceFilter = activeTab ? activeTab.dataset.device : null;

  renderNotesList(modal, deviceFilter);
}

function showNotesModal() {
  // Create modal if it doesn't exist
  let modal = document.querySelector(".sticky-notes-modal");
  if (!modal) {
    modal = document.createElement("div");
    modal.className = "sticky-notes-modal";
    modal.innerHTML = `
      <div class="sticky-notes-modal-content">
        <div class="sticky-modal-header">
          <h3 class="sticky-modal-title">Sticky Notes on This Page</h3>
          <button class="sticky-modal-close">&times;</button>
        </div>
        <div class="sticky-modal-tabs">
          <button class="sticky-tab-button" data-device="desktop">Desktop</button>
          <button class="sticky-tab-button" data-device="tablet">Tablet</button>
          <button class="sticky-tab-button" data-device="mobile">Mobile</button>
        </div>
        <div class="sticky-modal-body">
          <div class="sticky-notes-list"></div>
        </div>
      </div>
    `;
    document.body.appendChild(modal);

    // Auto-switch tabs on window resize
    const handleResize = () => {
      if (!modal.classList.contains("show")) return; // Only when modal is visible

      const newDeviceType = getCurrentDeviceType();
      if (newDeviceType !== currentDeviceFilter) {
        // Remove active class from all tabs
        tabButtons.forEach((btn) => btn.classList.remove("active"));
        // Add active class to new device tab
        const newActiveTab = modal.querySelector(
          `.sticky-tab-button[data-device="${newDeviceType}"]`
        );
        if (newActiveTab) {
          newActiveTab.classList.add("active");
        }
        // Update current device filter
        currentDeviceFilter = newDeviceType;
        // Re-render notes list with new filter
        renderNotesList(modal, currentDeviceFilter);
      }
    };

    window.addEventListener("resize", handleResize);

    // Close button handler
    modal.querySelector(".sticky-modal-close").addEventListener("click", () => {
      window.removeEventListener("resize", handleResize);
      modal.classList.remove("show");
    });

    // Tab switching handlers
    const tabButtons = modal.querySelectorAll(".sticky-tab-button");
    let currentDeviceFilter = getCurrentDeviceType(); // Set based on current screen size

    // Set initial active tab based on current device
    const initialActiveTab = modal.querySelector(
      `.sticky-tab-button[data-device="${currentDeviceFilter}"]`
    );
    if (initialActiveTab) {
      initialActiveTab.classList.add("active");
    }

    tabButtons.forEach((button) => {
      button.addEventListener("click", () => {
        // Remove active class from all tabs
        tabButtons.forEach((btn) => btn.classList.remove("active"));
        // Add active class to clicked tab
        button.classList.add("active");
        // Update current device filter
        currentDeviceFilter = button.dataset.device;
        // Re-render notes list with new filter
        renderNotesList(modal, currentDeviceFilter);
      });
    });

    // Close on backdrop click
    modal.addEventListener("click", (e) => {
      if (e.target === modal) {
        window.removeEventListener("resize", handleResize);
        modal.classList.remove("show");
      }
    });
  }

  // Populate with current notes, respecting completed-visibility toggle
  renderNotesList(modal, getCurrentDeviceType()); // Use current screen size

  // Show modal
  modal.classList.add("show");

  // Handle note actions
  const notesList = modal.querySelector(".sticky-notes-list");
  if (notesList && !notesList.__stickyHandlerAttached) {
    notesList.addEventListener("click", (e) => {
      if (
        e.target.classList.contains("sticky-row-toggle") ||
        (e.target.closest && e.target.closest(".sticky-row-toggle"))
      ) {
        const toggleBtn = e.target.classList.contains("sticky-row-toggle")
          ? e.target
          : e.target.closest(".sticky-row-toggle");
        const row = toggleBtn.closest(".sticky-list-row");
        if (row) {
          // When expanding, refresh details from the live note element
          const expandedBefore = row.classList.contains("open");
          if (!expandedBefore) {
            // Collapse any other open rows to ensure only one is expanded
            const openRows = notesList.querySelectorAll(
              ".sticky-list-row.open"
            );
            openRows.forEach((r) => {
              if (r !== row) {
                r.classList.remove("open");
                const tb = r.querySelector(".sticky-row-toggle");
                if (tb) {
                  tb.setAttribute("aria-expanded", "false");
                  const t = tb.querySelector(".sticky-toggle-text");
                  if (t) t.textContent = "Expand";
                }
              }
            });
            const noteId = row.getAttribute("data-note-id");
            const targetNote = document.querySelector(
              `.sticky-note[data-note-id="${noteId}"]`
            );
            if (targetNote) {
              const ta = targetNote.querySelector(".sticky-text");
              const content = ta ? ta.value : "";
              const assigned =
                targetNote.dataset.assignedTo ||
                (targetNote.querySelector(".sticky-assignee") &&
                  targetNote.querySelector(".sticky-assignee").value) ||
                "";
              const priority =
                parseInt(targetNote.dataset.priority || "2", 10) || 2;
              const pLabel =
                priority === 1 ? "Low" : priority === 3 ? "High" : "Medium";
              const isCompleted = targetNote.dataset.completed === "1";
              const body = row.querySelector(".sticky-list-row-body");
              if (body) {
                const contentBox = body.querySelector(".sticky-list-content");
                if (contentBox)
                  contentBox.textContent = content || "Empty note";
                const metaAssigned = body.querySelector(".meta-assigned");
                if (metaAssigned) metaAssigned.textContent = assigned || "n/a";
                const metaPriority = body.querySelector(".meta-priority");
                if (metaPriority) metaPriority.textContent = pLabel;
                const metaStatus = body.querySelector(".meta-status");
                if (metaStatus)
                  metaStatus.textContent = isCompleted ? "Completed" : "Active";
              }
              // Update header priority dot in case it changed
              const pr = row.querySelector(".sticky-list-priority");
              if (pr) {
                pr.innerHTML = `<span class=\"sticky-priority-dot ${
                  priority === 1
                    ? "priority-dot-low"
                    : priority === 3
                    ? "priority-dot-high"
                    : "priority-dot-medium"
                }\"></span>`;
              }
            }
          }
          row.classList.toggle("open");
          const expanded = row.classList.contains("open");
          toggleBtn.setAttribute("aria-expanded", expanded ? "true" : "false");
          const txt = toggleBtn.querySelector(".sticky-toggle-text");
          if (txt) txt.textContent = expanded ? "Collapse" : "Expand";
        }
      } else if (e.target.classList.contains("sticky-note-item-action")) {
        const action = e.target.dataset.action;
        const noteId = e.target.dataset.noteId;

        if (action === "focus") {
          // Find and focus the note
          const targetNote = document.querySelector(
            `.sticky-note[data-note-id="${noteId}"]`
          );
          if (targetNote) {
            try {
              document
                .querySelectorAll(".sticky-note.focused")
                .forEach((n) => n.classList.remove("focused"));
            } catch (_) {}
            targetNote.classList.add("focused");
            targetNote.scrollIntoView({ behavior: "smooth", block: "center" });
            targetNote.style.animation = "pulse 1s ease-in-out 2";
            modal.classList.remove("show");
          }
        } else if (action === "delete") {
          // Delete the note
          const targetNote = document.querySelector(
            `[data-note-id="${noteId}"]`
          );
          if (
            targetNote &&
            confirm("Are you sure you want to delete this note?")
          ) {
            targetNote.querySelector(".sticky-close").click();
            modal.classList.remove("show");
          }
        }
      }
    });
    notesList.__stickyHandlerAttached = true;
  }
}

// Update the note count on the bubble
function updateBubbleCount() {
  const bubble = document.querySelector(".sticky-bubble");
  const countElement = document.querySelector(".sticky-bubble-count");
  if (!bubble || !countElement) return;

  // Count only visible notes
  const visibleNotes = Array.from(
    document.querySelectorAll(".sticky-note")
  ).filter((note) => !note.classList.contains("sticky-hidden"));
  const noteCount = visibleNotes.length;

  if (noteCount > 0) {
    countElement.textContent = noteCount > 99 ? "99+" : noteCount;
    countElement.classList.remove("sticky-hidden");
    countElement.classList.add("sticky-flex");
  } else {
    countElement.classList.add("sticky-hidden");
    countElement.classList.remove("sticky-flex");
  }
}

// Global function to update count (called when notes are added/removed)
window.updateBubbleCount = updateBubbleCount;
