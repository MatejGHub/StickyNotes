document.addEventListener("DOMContentLoaded", function () {
  if (document.getElementById("sticky-comments-admin")) {
    const tabs = document.querySelectorAll(".nav-tab");
    const contents = {
      sticky:
        document.getElementById("tab-content-sticky-notes") ||
        document.getElementById("tab-content-data"),
      links: document.getElementById("tab-content-links"),
      settings: document.getElementById("tab-content-settings"),
    };

    function getInitialHash() {
      const hash = window.location.hash;
      if (hash === "#shared-links" || hash === "#settings" || hash === "#sticky-notes") {
        return hash;
      }
      const params = new URLSearchParams(window.location.search);
      const tab = params.get("tab");
      if (tab === "links") return "#shared-links";
      if (tab === "settings") return "#settings";
      return "#sticky-notes";
    }

    tabs.forEach((tab) => {
      tab.addEventListener("click", function (e) {
        e.preventDefault();
        tabs.forEach((t) => t.classList.remove("nav-tab-active"));
        tab.classList.add("nav-tab-active");
        Object.values(contents).forEach((c) => {
          c && c.classList.add("sticky-hidden");
          c && c.classList.remove("sticky-block");
        });
        if (tab.id === "tab-sticky-notes") {
          contents.sticky && contents.sticky.classList.remove("sticky-hidden");
          contents.sticky && contents.sticky.classList.add("sticky-block");
          window.location.hash = "#sticky-notes";
        }
        if (tab.id === "tab-links") {
          contents.links && contents.links.classList.remove("sticky-hidden");
          contents.links && contents.links.classList.add("sticky-block");
          window.location.hash = "#shared-links";
        }
        if (tab.id === "tab-settings") {
          contents.settings &&
            contents.settings.classList.remove("sticky-hidden");
          contents.settings && contents.settings.classList.add("sticky-block");
          window.location.hash = "#settings";
        }
      });
    });

    const setActiveFromHash = () => {
      const hash = getInitialHash();
      tabs.forEach((t) => t.classList.remove("nav-tab-active"));
      Object.values(contents).forEach((c) => {
        c && c.classList.add("sticky-hidden");
        c && c.classList.remove("sticky-block");
      });
      if (hash === "#settings") {
        document
          .getElementById("tab-settings")
          ?.classList.add("nav-tab-active");
        contents.settings &&
          contents.settings.classList.remove("sticky-hidden");
        contents.settings && contents.settings.classList.add("sticky-block");
      } else if (hash === "#shared-links") {
        document.getElementById("tab-links")?.classList.add("nav-tab-active");
        contents.links && contents.links.classList.remove("sticky-hidden");
        contents.links && contents.links.classList.add("sticky-block");
      } else {
        document
          .getElementById("tab-sticky-notes")
          ?.classList.add("nav-tab-active");
        contents.sticky && contents.sticky.classList.remove("sticky-hidden");
        contents.sticky && contents.sticky.classList.add("sticky-block");
      }
    };

    window.addEventListener("hashchange", setActiveFromHash);
    if (!window.location.hash || window.location.hash === "#") {
      const hash = getInitialHash();
      if (window.history && window.history.replaceState) {
        window.history.replaceState(
          null,
          "",
          window.location.pathname + window.location.search + hash
        );
      } else {
        window.location.hash = hash;
      }
    }
    setActiveFromHash();
  }

  // Add Global Sticky Note button -> open modal-like backdrop and spawn a centered sticky note
  const addGlobalBtn = document.getElementById("sticky-add-global-note");
  if (addGlobalBtn) {
    // Track if button is temporarily disabled (debouncing)
    let isButtonDisabled = false;
    // Track if any dashboard note is currently open
    let isNoteBeingEdited = false;

    // Function to update button state
    const updateButtonState = () => {
      const hasOpenNote =
        document.querySelectorAll(".sticky-note.dashboard-note").length > 0;
      const shouldDisable = isButtonDisabled || hasOpenNote;
      addGlobalBtn.disabled = shouldDisable;
      addGlobalBtn.textContent = shouldDisable
        ? hasOpenNote
          ? "Note Open"
          : "Creating..."
        : "Add Global Sticky Note";
      addGlobalBtn.style.opacity = shouldDisable ? "0.6" : "1";
    };

    // Function to temporarily disable button after click
    const disableButtonTemporarily = () => {
      isButtonDisabled = true;
      updateButtonState();
      setTimeout(() => {
        isButtonDisabled = false;
        updateButtonState();
      }, 3000); // 3 seconds debounce
    };

    // Monitor for note creation/closing to update button state
    const observer = new MutationObserver(() => {
      const hasOpenNotes =
        document.querySelectorAll(".sticky-note.dashboard-note").length > 0;
      if (isNoteBeingEdited !== hasOpenNotes) {
        isNoteBeingEdited = hasOpenNotes;
        updateButtonState();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Initialize button state on page load
    updateButtonState();

    addGlobalBtn.addEventListener("click", function (e) {
      e.preventDefault();
      // Check if button is currently disabled
      if (isButtonDisabled || isNoteBeingEdited) {
        return;
      }

      // Ensure required APIs exist
      if (typeof window.createStickyNote !== "function") {
        alert("Sticky note scripts are not loaded yet.");
        return;
      }

      // Start debouncing immediately
      disableButtonTemporarily();

      // Allow only one dashboard note open at a time
      try {
        document
          .querySelectorAll(".sticky-note.dashboard-note")
          .forEach(function (n) {
            n.remove();
          });
        const oldBackdrop = document.getElementById(
          "sticky-dashboard-backdrop"
        );
        if (oldBackdrop) oldBackdrop.remove();
      } catch (_) {}

      // Compute a pleasant vertical position (~30% from top of viewport)
      const docW = document.documentElement.scrollWidth || window.innerWidth;
      const docH = document.documentElement.scrollHeight || window.innerHeight;
      const xPercent = 50;
      const yPercent = 30;

      // Create the sticky note centered; no element path for admin/global
      const wrapper = window.createStickyNote(xPercent, yPercent, "", {
        skipLimit: true,
      });
      if (!wrapper) return;
      // Mark as dashboard note for styling/behavior targeting
      try {
        wrapper.classList.add("dashboard-note");
      } catch (_) {}

      // No validation on open; allow user to edit first. Validation occurs on Save.

      // Create a dimmed backdrop that sits UNDER the note (lower z-index)
      const backdrop = document.createElement("div");
      backdrop.id = "sticky-dashboard-backdrop";
      backdrop.style.position = "fixed";
      backdrop.style.top = "0";
      backdrop.style.left = "0";
      backdrop.style.right = "0";
      backdrop.style.bottom = "0";
      backdrop.style.background = "rgba(0,0,0,0.45)";
      backdrop.style.zIndex = "9998"; 
      backdrop.setAttribute("aria-hidden", "true");
      document.body.appendChild(backdrop);

      // Close on backdrop click (simulate clicking the note's close)
      const closeBtn = wrapper.querySelector(".sticky-close");
      backdrop.addEventListener("click", function () {
        try {
          closeBtn && closeBtn.click();
        } catch (_) {}
        try {
          backdrop.remove();
        } catch (_) {}
      });
      // When note closes, remove backdrop too
      if (closeBtn) {
        closeBtn.addEventListener("click", function () {
          try {
            backdrop.remove();
          } catch (_) {}
        });
      }

      // Do not create DB record on open; wait for explicit Save

      // Save handling: validate on Save, wait for successful save (note_id) then update table and close
      try {
        const saveBtn = wrapper.querySelector(".sticky-comment-save");
        const textArea = wrapper.querySelector("textarea");
        if (saveBtn) {
          // Capture phase to run before internal save handler
          saveBtn.addEventListener(
            "click",
            function (ev) {
              const content = textArea ? textArea.value.trim() : "";
              const assigned = wrapper.dataset.assignedTo || "";
              const priority =
                parseInt(wrapper.dataset.priority || "2", 10) || 2;
              const isCompleted = wrapper.dataset.completed === "1";
              const hasContent = content.length > 0;
              const hasAssignment = assigned.length > 0;
              const hasCustomPriority = priority !== 2;
              const isCompletedNote = isCompleted;
              if (
                !hasContent &&
                !hasAssignment &&
                !hasCustomPriority &&
                !isCompletedNote
              ) {
                ev.preventDefault();
                ev.stopImmediatePropagation();
                if (window.stickyFeedback) {
                  window.stickyFeedback.showNotification(
                    "Please add content, assign someone, or set a priority before saving your sticky note.",
                    "warning",
                    4000
                  );
                }
                return;
              }
              // After allowing the default save handler to run, observe for note_id to appear
              const observer = new MutationObserver(function () {
                const id =
                  wrapper && wrapper.dataset ? wrapper.dataset.noteId : "";
                if (id) {
                  try {
                    upsertGlobalAdminRow({
                      id,
                      content: (textArea && textArea.value) || "",
                      assigned,
                      isCompleted,
                      priority,
                    });
                  } catch (_) {}
                  try {
                    wrapper.remove();
                  } catch (_) {}
                  try {
                    backdrop.remove();
                  } catch (_) {}
                  observer.disconnect();
                }
              });
              observer.observe(wrapper, {
                attributes: true,
                attributeFilter: ["data-note-id"],
              });
            },
            true
          );
        }
      } catch (_) {}
    });
  }

  // ===== Helpers for live injection into admin table =====
  function getHiddenColumnsMap() {
    const map = {
      author: false,
      content: false,
      images: false,
      created: false,
      updated: false,
      priority: false,
    };
    try {
      document.querySelectorAll(".sticky-column-toggle").forEach(function (cb) {
        const col = cb.getAttribute("data-column");
        if (col && Object.prototype.hasOwnProperty.call(map, col)) {
          map[col] = !cb.checked; // hidden if unchecked
        }
      });
    } catch (_) {}
    return map;
  }

  // Reopen a note inline in the dashboard (no navigation)
  document.addEventListener("click", function (e) {
    const link =
      e.target &&
      e.target.closest &&
      e.target.closest(".wp-list-table .column-view a");
    if (!link) return;

    const row = link.closest("tr");
    const container =
      row && row.closest && row.closest(".post-table-container");
    let postSlug = "";
    if (container) {
      const prev = container.previousElementSibling;
      if (
        prev &&
        prev.classList &&
        prev.classList.contains("post-toggle-header")
      ) {
        postSlug = prev.getAttribute("data-post-slug") || "";
      }
    }

    // Only intercept for Global section (post-0). Otherwise let the browser follow the link to frontend.
    if (postSlug !== "post-0") {
      return;
    }
    e.preventDefault();

    let noteId = "";
    try {
      const href = link.getAttribute("href") || "";
      const m = href.match(/#sticky-note-(\d+)/);
      if (m && m[1]) noteId = m[1];
    } catch (_) {}
    if (!noteId && row) {
      const idCell = row.querySelector(".column-id");
      if (idCell) noteId = (idCell.textContent || "").trim();
    }
    if (!noteId) return;

    // If the note is already open in dashboard, just focus it (no reposition)
    const existing = document.querySelector(
      '.sticky-note.dashboard-note[data-note-id="' + noteId + '"]'
    );
    if (existing) {
      try {
        existing.classList.add("focused");
        // Ensure completed notes are made visible if previously hidden
        existing.classList.remove("sticky-hidden");
        existing.classList.add("sticky-block");
      } catch (_) {}
      return;
    }

    // Ensure only one dashboard note at a time
    try {
      document
        .querySelectorAll(".sticky-note.dashboard-note")
        .forEach(function (n) {
          n.remove();
        });
      const oldBackdrop = document.getElementById("sticky-dashboard-backdrop");
      if (oldBackdrop) oldBackdrop.remove();
    } catch (_) {}

    // Extract details from the table row
    const contentCell = row.querySelector(".column-content");
    const assignedCell = row.querySelector(".column-assigned_to");
    const completedCell = row.querySelector(".column-completed");
    const priorityDot = row.querySelector(
      ".column-priority .sticky-priority-dot"
    );

    const content = contentCell ? contentCell.textContent : "";
    const assigned = assignedCell ? assignedCell.textContent.trim() : "";
    const isCompleted = completedCell
      ? /yes/i.test(completedCell.textContent || "")
      : false;
    const priority = priorityDot
      ? priorityDot.classList.contains("priority-dot-low")
        ? 1
        : priorityDot.classList.contains("priority-dot-high")
        ? 3
        : 2
      : 2;

    // Temporarily override the close handler to avoid deleting the note from admin reopen
    const originalSetup =
      typeof window.setupStickyNoteCloseHandler === "function"
        ? window.setupStickyNoteCloseHandler
        : null;
    window.setupStickyNoteCloseHandler = function (wrapper, closeButton) {
      closeButton.addEventListener("click", function () {
        try {
          wrapper.remove();
        } catch (_) {}
      });
    };

    // Create centered note within dashboard
    const wrapper = window.createStickyNote(50, 30, "", {
      skipLimit: true,
      markCompleted: isCompleted,
    });

    // Restore the original handler for any other notes
    if (originalSetup) {
      window.setupStickyNoteCloseHandler = originalSetup;
    } else {
      try {
        delete window.setupStickyNoteCloseHandler;
      } catch (_) {}
    }

    if (!wrapper) return;
    // Mark as dashboard note for styling/behavior targeting
    try {
      wrapper.classList.add("dashboard-note");
    } catch (_) {}
    // Re-center the note within the current viewport
    try {
      const vw = Math.max(
        document.documentElement.clientWidth || 0,
        window.innerWidth || 0
      );
      const vh = Math.max(
        document.documentElement.clientHeight || 0,
        window.innerHeight || 0
      );
      const noteW = Math.max(wrapper.offsetWidth || 0, 100);
      const noteH = Math.max(wrapper.offsetHeight || 0, 100);
      const scrollX =
        window.pageXOffset || document.documentElement.scrollLeft || 0;
      const scrollY =
        window.pageYOffset || document.documentElement.scrollTop || 0;
      const left = scrollX + Math.max(0, (vw - noteW) / 2);
      const top = scrollY + Math.max(0, (vh - noteH) / 3);
      wrapper.style.left = left + "px";
      wrapper.style.top = top + "px";
    } catch (_) {}
    wrapper.dataset.noteId = String(noteId);
    wrapper.dataset.assignedTo = assigned || "";
    wrapper.dataset.priority = String(priority);
    wrapper.dataset.completed = isCompleted ? "1" : "0";

    // Update priority badge to reflect current value
    try {
      const badge = wrapper.querySelector(".sticky-priority-badge");
      if (badge) {
        const dotClass =
          priority === 1
            ? "priority-dot-low"
            : priority === 3
            ? "priority-dot-high"
            : "priority-dot-medium";
        badge.classList.remove("sticky-hidden");
        badge.classList.add("sticky-flex");
        badge.innerHTML =
          '<span class="sticky-priority-dot ' + dotClass + '"></span>';
      }
    } catch (_) {}

    // Populate textarea and update character counter
    try {
      const ta = wrapper.querySelector("textarea");
      if (ta) {
        const MAX_CHARS = 500;
        ta.value = (content || "").slice(0, MAX_CHARS);
        const counterWrap = wrapper.querySelector(".sticky-char-counter-wrap");
        if (counterWrap) {
          const counterCount = counterWrap.querySelector(".sticky-char-count");
          if (counterCount) {
            const len = Math.min(ta.value.length, MAX_CHARS);
            counterCount.textContent = String(len);
          }
        }
      }
    } catch (_) {}

    // Sync table row when user saves/changes the reopened note
    try {
      const saveBtn = wrapper.querySelector(".sticky-comment-save");
      const textArea = wrapper.querySelector("textarea");
      const noteIdStr = String(noteId);

      function updateRowFromWrapper() {
        const assignedNow = wrapper.dataset.assignedTo || "";
        const isCompletedNow = wrapper.dataset.completed === "1";
        const prNow = parseInt(wrapper.dataset.priority || "2", 10) || 2;
        const contentNow = textArea ? textArea.value || "" : "";
        upsertGlobalAdminRow({
          id: noteIdStr,
          content: contentNow,
          assigned: assignedNow,
          isCompleted: isCompletedNow,
          priority: prNow,
        });
      }

      if (saveBtn) {
        saveBtn.addEventListener("click", function () {
          // Update table immediately when saving
          updateRowFromWrapper();
          // Close the dashboard note after save click (admin-only behavior)
          try {
            wrapper.remove();
          } catch (_) {}
          try {
            const bd = document.getElementById("sticky-dashboard-backdrop");
            if (bd) bd.remove();
          } catch (_) {}
        });
      }

      // Attribute changes (priority/complete/assign) -> update row
      const mo = new MutationObserver(function (mutList) {
        let relevant = false;
        mutList.forEach(function (m) {
          if (
            m.type === "attributes" &&
            (m.attributeName === "data-priority" ||
              m.attributeName === "data-completed" ||
              m.attributeName === "data-assigned-to")
          ) {
            relevant = true;
          }
        });
        if (relevant) updateRowFromWrapper();
      });
      mo.observe(wrapper, {
        attributes: true,
        attributeFilter: [
          "data-priority",
          "data-completed",
          "data-assigned-to",
        ],
      });

      // On blur of text area, reflect content change as well
      if (textArea) {
        textArea.addEventListener("blur", updateRowFromWrapper);
      }
    } catch (_) {}
  });

  // ===== Drag & Drop reordering of sections (header + container move together) =====
  initializeSectionReorder();

  function initializeSectionReorder() {
    try {
      const headers = Array.from(
        document.querySelectorAll(".post-toggle-header")
      );
      if (!headers.length) return;

      // Apply saved order first
      applySavedSectionOrder();

      // Attach drag handlers
      headers.forEach((h) => attachDragBehavior(h));

      // Guard against accidental toggle on drag
      document.addEventListener(
        "click",
        function (e) {
          const dragging = document.querySelector(
            '.post-toggle-header[data-dragging="1"]'
          );
          if (dragging) {
            e.stopPropagation();
            e.preventDefault();
          }
        },
        true
      );
    } catch (_) {}
  }

  function attachDragBehavior(header) {
    try {
      header.setAttribute("draggable", "true");
      let draggingHeader = null;
      let lastOverHeader = null;

      header.addEventListener("dragstart", function (e) {
        draggingHeader = header;
        header.setAttribute("data-dragging", "1");
        try {
          e.dataTransfer.effectAllowed = "move";
          e.dataTransfer.setData("text/plain", header.dataset.postId || "");
        } catch (_) {}
        // Visual cue
        header.style.opacity = "0.6";
      });

      header.addEventListener("dragend", function () {
        header.removeAttribute("data-dragging");
        header.style.opacity = "";
        if (lastOverHeader) {
          lastOverHeader.style.outline = "";
          lastOverHeader = null;
        }
        saveCurrentSectionOrder();
      });

      document.addEventListener("dragover", function (e) {
        if (!draggingHeader) return;
        e.preventDefault();
        const over =
          e.target && e.target.closest
            ? e.target.closest(".post-toggle-header")
            : null;
        if (!over || over === draggingHeader) return;

        // Highlight target
        if (lastOverHeader && lastOverHeader !== over) {
          lastOverHeader.style.outline = "";
        }
        lastOverHeader = over;
        over.style.outline = "2px dashed #cbd5e1";

        // Decide before/after by mouse position
        const rect = over.getBoundingClientRect();
        const before = e.clientY < rect.top + rect.height / 2;
        moveSection(draggingHeader, over, before ? "before" : "after");
      });

      document.addEventListener("drop", function (e) {
        if (!draggingHeader) return;
        e.preventDefault();
        // Clean highlight
        if (lastOverHeader) {
          lastOverHeader.style.outline = "";
          lastOverHeader = null;
        }
        // Stop dragging
        draggingHeader.removeAttribute("data-dragging");
        draggingHeader.style.opacity = "";
        draggingHeader = null;
        saveCurrentSectionOrder();
      });
    } catch (_) {}
  }

  function getSectionPairFromHeader(h) {
    const pair = { header: h, container: null };
    let sib = h.nextElementSibling;
    while (sib && !sib.classList.contains("post-table-container")) {
      sib = sib.nextElementSibling;
    }
    pair.container = sib || null;
    return pair;
  }

  function moveSection(srcHeader, targetHeader, where) {
    if (!srcHeader || !targetHeader || srcHeader === targetHeader) return;
    const parent = srcHeader.parentNode;
    if (!parent || targetHeader.parentNode !== parent) return;

    const src = getSectionPairFromHeader(srcHeader);
    const tgt = getSectionPairFromHeader(targetHeader);
    if (!src.header || !src.container || !tgt.header) return;

    // Temporarily mark to retain relative order on moves
    const srcContainer = src.container;
    // Remove src from DOM
    parent.removeChild(src.header);
    if (srcContainer && srcContainer.parentNode === parent)
      parent.removeChild(srcContainer);

    if (where === "before") {
      parent.insertBefore(src.header, tgt.header);
      if (srcContainer) parent.insertBefore(srcContainer, tgt.header);
    } else {
      // after target's container (or header if missing)
      const insertAfter =
        tgt.container && tgt.container.parentNode === parent
          ? tgt.container.nextSibling
          : tgt.header.nextSibling;
      parent.insertBefore(src.header, insertAfter);
      if (srcContainer) parent.insertBefore(srcContainer, insertAfter);
    }
  }

  function saveCurrentSectionOrder() {
    try {
      const headers = Array.from(
        document.querySelectorAll(".post-toggle-header")
      );
      const order = headers.map((h) => String(h.dataset.postId || ""));
      localStorage.setItem("sticky_sections_order", JSON.stringify(order));
    } catch (_) {}
  }

  function applySavedSectionOrder() {
    try {
      const raw = localStorage.getItem("sticky_sections_order");
      if (!raw) return;
      const order = JSON.parse(raw);
      if (!Array.isArray(order) || !order.length) return;
      const parent = (function () {
        const firstHeader = document.querySelector(".post-toggle-header");
        return firstHeader ? firstHeader.parentNode : null;
      })();
      if (!parent) return;

      order.forEach(function (postId) {
        const header = document.querySelector(
          '.post-toggle-header[data-post-id="' +
            CSS.escape(String(postId)) +
            '"]'
        );
        if (!header || header.parentNode !== parent) return;
        const pair = getSectionPairFromHeader(header);
        parent.appendChild(pair.header);
        if (pair.container) parent.appendChild(pair.container);
      });
    } catch (_) {}
  }

  function ensureGlobalSection() {
    // Try to find existing Global group (post_id = 0)
    let header = document.querySelector(
      '.post-toggle-header[data-post-id="0"]'
    );
    let container = document.querySelector(
      '.post-table-container[data-post-id="0"]'
    );
    if (header && container) {
      const table = container.querySelector("table.wp-list-table");
      const tbody = table ? table.querySelector("tbody") : null;
      return { header, container, table, tbody };
    }

    // Create fresh Global section right after the header area
    const parentWrap =
      document.querySelector("#tab-content-sticky-notes .wrap") ||
      document.querySelector("#tab-content-sticky-notes");
    const insertAfter =
      document.querySelector(".all-sticky-notes-header") || parentWrap;
    const hidden = getHiddenColumnsMap();

    header = document.createElement("h2");
    header.className = "post-toggle-header";
    header.setAttribute("data-post-id", "0");
    header.setAttribute("data-post-slug", "post-0");
    header.textContent = "Global";

    container = document.createElement("div");
    container.className = "post-table-container";
    container.setAttribute("data-post-id", "0");

    const table = document.createElement("table");
    table.className = "widefat fixed striped wp-list-table";
    const thead = document.createElement("thead");
    const trh = document.createElement("tr");
    trh.innerHTML =
      "" +
      '<th class="manage-column column-id">ID</th>' +
      (hidden.author
        ? ""
        : '<th class="manage-column column-author">Author</th>') +
      (hidden.content
        ? ""
        : '<th class="manage-column column-content">Content</th>') +
      (hidden.images
        ? ""
        : '<th class="manage-column column-images">Images</th>') +
      '<th class="manage-column column-assigned_to">Assigned To</th>' +
      '<th class="manage-column column-completed">Completed</th>' +
      (hidden.created
        ? ""
        : '<th class="manage-column column-created">Created</th>') +
      (hidden.updated
        ? ""
        : '<th class="manage-column column-updated">Updated</th>') +
      '<th class="manage-column column-view">View</th>' +
      (hidden.priority
        ? ""
        : '<th class="manage-column column-priority">Priority</th>') +
      '<th class="manage-column column-delete">Delete</th>';
    thead.appendChild(trh);
    const tbody = document.createElement("tbody");
    table.appendChild(thead);
    table.appendChild(tbody);
    container.appendChild(table);

    // Insert into DOM
    if (insertAfter && insertAfter.parentNode) {
      insertAfter.parentNode.insertBefore(header, insertAfter.nextSibling);
      header.parentNode.insertBefore(container, header.nextSibling);
    } else {
      const anchor =
        document.getElementById("tab-content-sticky-notes") || document.body;
      anchor.appendChild(header);
      anchor.appendChild(container);
    }

    // Attach simple toggle behavior for this new header
    try {
      header.addEventListener("click", function () {
        const key = "sticky_post_toggle_0";
        const isCollapsed = header.classList.contains("collapsed");
        if (isCollapsed) {
          header.classList.remove("collapsed");
          container.classList.remove("collapsed");
          try {
            localStorage.setItem(key, "false");
          } catch (_) {}
        } else {
          header.classList.add("collapsed");
          container.classList.add("collapsed");
          try {
            localStorage.setItem(key, "true");
          } catch (_) {}
        }
      });
    } catch (_) {}

    return { header, container, table, tbody };
  }

  function upsertGlobalAdminRow({
    id,
    content,
    assigned,
    isCompleted,
    priority,
  }) {
    const section = ensureGlobalSection();
    if (!section || !section.tbody) return;

    // Try to find existing row for this ID
    let row = section.tbody.querySelector('tr[data-note-id="' + id + '"]');
    const hidden = getHiddenColumnsMap();

    const assignedDisplay = (function () {
      if (!assigned) return "";
      // Only show @username pattern similar to PHP cleanup
      const atMatch = assigned.match(/(^|\s)@([\w\-.]+)/);
      return atMatch ? "@" + atMatch[2] : assigned;
    })();

    const priorityClass =
      priority === 1
        ? "priority-dot-low"
        : priority === 3
        ? "priority-dot-high"
        : "priority-dot-medium";
    const completedText = isCompleted ? "Yes" : "No";

    if (!row) {
      row = document.createElement("tr");
      row.setAttribute("data-note-id", String(id));
      if (isCompleted) row.classList.add("sticky-row-completed");
      row.innerHTML =
        "" +
        '<td class="column-id">' +
        String(id) +
        "</td>" +
        (hidden.author
          ? ""
          : '<td class="column-author">' +
            (window.my_ajax_object && window.my_ajax_object.current_user_display
              ? window.my_ajax_object.current_user_display
              : window.my_ajax_object &&
                window.my_ajax_object.current_user_login
              ? window.my_ajax_object.current_user_login
              : "Unknown") +
            "</td>") +
        (hidden.content ? "" : '<td class="column-content"></td>') +
        (hidden.images
          ? ""
          : '<td class="column-images"><span style="color:#94a3b8;font-style:italic;">No images</span></td>') +
        '<td class="column-assigned_to"></td>' +
        '<td class="column-completed">' +
        completedText +
        "</td>" +
        (hidden.created ? "" : '<td class="column-created"></td>') +
        (hidden.updated ? "" : '<td class="column-updated"></td>') +
        '<td class="column-view"><a href="#sticky-note-' +
        String(id) +
        '" target="_blank" class="button button-small">View</a></td>' +
        (hidden.priority
          ? ""
          : '<td class="column-priority"><span class="sticky-priority-dot ' +
            priorityClass +
            '" title="Priority"></span></td>') +
        '<td class="column-delete"><button class="button button-small sticky-admin-delete" data-note-id="' +
        String(id) +
        '">Delete</button></td>';
      section.tbody.prepend(row);
      // Attach delete handler for this new row
      const delBtn = row.querySelector(".sticky-admin-delete");
      if (delBtn) {
        delBtn.addEventListener("click", function (ev) {
          ev.preventDefault();
          const noteId = this.getAttribute("data-note-id");
          if (!noteId) return;
          if (!confirm("Delete this note?")) return;
          fetch(
            window.ajaxurl ||
              (window.my_ajax_object && window.my_ajax_object.ajax_url),
            {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: new URLSearchParams({
                action: "delete_sticky_note",
                note_id: noteId,
                nonce:
                  (window.my_ajax_object && window.my_ajax_object.nonce) || "",
              }),
            }
          )
            .then((r) => r.json())
            .then((data) => {
              if (data && data.success) {
                try {
                  row.remove();
                } catch (_) {}
              } else {
                alert(
                  data && (data.error || data.data)
                    ? data.error || data.data
                    : "Delete failed"
                );
              }
            })
            .catch(() => alert("Delete failed"));
        });
      }
    }

    // Update mutable cells
    const contentCell = row.querySelector(".column-content");
    if (contentCell) contentCell.textContent = String(content || "");
    const assignedCell = row.querySelector(".column-assigned_to");
    if (assignedCell) assignedCell.textContent = assignedDisplay;
    const completedCell = row.querySelector(".column-completed");
    if (completedCell) completedCell.textContent = completedText;
    // Keep row completed styling in sync
    if (isCompleted) {
      row.classList.add("sticky-row-completed");
    } else {
      row.classList.remove("sticky-row-completed");
    }
    const priorityCell = row.querySelector(
      ".column-priority .sticky-priority-dot"
    );
    if (priorityCell) {
      priorityCell.classList.remove(
        "priority-dot-low",
        "priority-dot-medium",
        "priority-dot-high"
      );
      priorityCell.classList.add(priorityClass);
    }

    // Touch updated time cell with a quick humanized stamp
    const updatedCell = row.querySelector(".column-updated");
    if (updatedCell) {
      try {
        const d = new Date();
        updatedCell.textContent = d.toLocaleString();
      } catch (_) {}
    }
  }
});
