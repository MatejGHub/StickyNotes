document.addEventListener("DOMContentLoaded", function () {
  const postId = Number(my_ajax_object && my_ajax_object.post_id);
  const pageUrl =
    (my_ajax_object && my_ajax_object.page_url) || window.location.href;
  if ((!postId || Number.isNaN(postId)) && !pageUrl) {
    return;
  }

  // If URL contains a sticky note hash, extract its numeric ID so the backend includes it even if completed
  let includeId = 0;
  if (
    window.location.hash &&
    window.location.hash.startsWith("#sticky-note-")
  ) {
    const idStr = window.location.hash.replace("#sticky-note-", "");
    const maybe = parseInt(idStr, 10);
    if (!isNaN(maybe)) includeId = maybe;
  }

  const fetchParams = {
    action: "get_sticky_notes_by_post_id",
    nonce: my_ajax_object.nonce,
    post_id: postId && !Number.isNaN(postId) ? String(postId) : "0",
    page_url: pageUrl,
    include_id: includeId ? String(includeId) : "",
    include_completed: "1",
  };
  if (my_ajax_object.is_guest === 1 && my_ajax_object.guest_token && my_ajax_object.guest_id) {
    fetchParams.guest_token = my_ajax_object.guest_token;
    fetchParams.guest_id = my_ajax_object.guest_id;
  }
  fetch(my_ajax_object.ajax_url, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: new URLSearchParams(fetchParams),
  })
    .then((res) => {
      return res.json();
    })
    .then((data) => {
      if (data.success && Array.isArray(data.data)) {
        data.data.forEach((note) => {
          const x = parseFloat(note.position_x);
          const y = parseFloat(note.position_y);
          const isCompleted =
            Number(note.is_completed) === 1 || Number(note.is_done) === 1;
          const wrapper =
            window.StickyComment && window.StickyComment.createStickyNote
              ? window.StickyComment.createStickyNote(x, y, "", {
                  skipLimit: isCompleted,
                  markCompleted: isCompleted,
                  device: note.device || "",
                })
              : window.createStickyNote(x, y, "", {
                  skipLimit: isCompleted,
                  markCompleted: isCompleted,
                  device: note.device || "",
                });
          if (!wrapper) {
            return;
          }

          const textarea = wrapper.querySelector("textarea");
          const MAX_CHARS = 500;
          textarea.value = (note.content || "").slice(0, MAX_CHARS);
          try {
            // update counter UI if present (don't dispatch input event to avoid showing save button on load)
            const counterWrap = wrapper.querySelector(
              ".sticky-char-counter-wrap"
            );
            if (counterWrap) {
              const counterCount =
                counterWrap.querySelector(".sticky-char-count");
              if (counterCount) {
                const len = Math.min(textarea.value.length, MAX_CHARS);
                counterCount.textContent = String(len);
              }
            }
          } catch (_) {}
          // Set title if present
          if (note.title) {
            const titleText = wrapper.querySelector(".sticky-title-text");
            if (titleText) titleText.textContent = note.title;
            wrapper.dataset.title = note.title;
          }

          // Set priority on wrapper and update badge on header
          const p = Number(note.priority) || 2;
          wrapper.dataset.priority = String(p);
          const badge = wrapper.querySelector(".sticky-priority-badge");
          if (badge) {
            let cls;
            if (p === 1) {
              cls = "priority-dot-low";
            } else if (p === 2) {
              cls = "priority-dot-medium";
            } else {
              cls = "priority-dot-high";
            }
            badge.classList.remove("sticky-hidden");
            badge.classList.add("sticky-flex");
            badge.innerHTML =
              '<span class="sticky-priority-dot ' + cls + '"></span>';
          }

          // Populate images if provided by backend
          try {
            const ids = note.images ? JSON.parse(note.images) : [];
            const urls = Array.isArray(note.image_urls) ? note.image_urls : [];
            if (typeof wrapper.setNoteImages === "function") {
              wrapper.setNoteImages(ids, urls);
            }
          } catch (_) {}

          // Populate comments if provided by backend
          try {
            const comments = note.comments ? JSON.parse(note.comments) : [];
            if (typeof wrapper.setComments === "function") {
              wrapper.setComments(Array.isArray(comments) ? comments : []);
            }
          } catch (_) {}

          // Set assigned value for newly created note API (via closure/global)
          if (typeof wrapper !== "undefined") {
            // Try to reflect assigned_to in header menu state by attaching to dataset
            wrapper.dataset.assignedTo = note.assigned_to || "";
          }

          // Keep completed notes hidden by default unless this is the included ID from URL
          if (isCompleted) {
            if (includeId && Number(note.id) === includeId) {
              // Force this one visible for permalink view
              wrapper.classList.remove("sticky-hidden");
              wrapper.classList.add("sticky-block");
            } else {
              wrapper.classList.add("sticky-hidden");
              wrapper.classList.remove("sticky-block");
            }
            // Flag as completed for toggle logic and CSS styling
            wrapper.dataset.completed = "1";
          } else {
            // Mark active notes explicitly
            wrapper.dataset.completed = "0";
          }

          const assigneeSelect = wrapper.querySelector(
            ".sticky-assignee-select"
          );
          if (assigneeSelect) {
            assigneeSelect.value = note.assigned_to || "";
          }

          wrapper.dataset.noteId = note.id;
          wrapper.id = `sticky-note-${note.id}`;

          // No manual increment here; count is handled when wrappers are created/removed
          // No per-note collapse anymore
        });

        // Update bubble count after loading notes
        setTimeout(() => {
          if (window.updateBubbleCount) {
            window.updateBubbleCount();
          }
          // If URL contains a hash for a sticky note, scroll to it
          if (
            window.location.hash &&
            window.location.hash.startsWith("#sticky-note-")
          ) {
            const target = document.querySelector(window.location.hash);
            if (target) {
              target.scrollIntoView({ behavior: "smooth", block: "center" });
              target.classList.add("sticky-pulse-animation");
              // Remove the animation class after it completes
              setTimeout(() => {
                target.classList.remove("sticky-pulse-animation");
              }, 2000);
            }
          }
        }, 100);
      } else {
        console.error("Failed to fetch sticky notes:", data.data || data.error);
      }
    })
    .catch((err) => {
      console.error("Error fetching sticky notes:", err);
    });
});
