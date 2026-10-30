# Rollback

A leaf page nested inside the `Deployment` folder, so it renders to
`Deployment/rollback/index.html`.

To check a republish: delete this file, rebuild `collectives-mock`, and run the
same build again. The page must be gone from the published site; if it is still
there, the new build was merged into the old one instead of replacing it.
