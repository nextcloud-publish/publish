# Release checklist

A leaf page two levels deep, so the relative links the renderer threads through
its recursion (`../../style.css` from here, not `../style.css`) are covered by a
real page rather than only by unit tests.

- [ ] `q.builds` drains after a publish
- [ ] `output/` holds one directory per page
- [ ] every page's stylesheet link resolves
