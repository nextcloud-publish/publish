# Rollback

A leaf page nested inside the `Deployment` folder, so it renders to
`Deployment/rollback/index.html`.

Handy as a republish probe: delete this file, rebuild `collectives-mock`, and
run the same build again. If the promoter is swapping directories correctly the
page disappears from the published site. If it were merging trees instead, it
would still be there.
