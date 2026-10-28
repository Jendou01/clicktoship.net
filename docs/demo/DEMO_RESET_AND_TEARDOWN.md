# Stop, reset, redeploy and teardown

Only use the exact operator-generated /run/cts-demo-<12 hex> directory, controller arguments from REMOTE_DEMO_SETUP. Never point this profile at a retained project, staging/production DB, shared volume or unreviewed Compose overlay.

* `demo stop`: stop only this project's services. Treat data as lost: tmpfs volume content may disappear once all users unmount. This is not a backup or guaranteed pause/resume operation. Keep gateway ingress closed before stopping writers.
* `demo teardown`: controller validates the root-private resources.json receipt against exact live container/network IDs, image IDs, mounts, canonical names and tmpfs volume creation identity, then removes only the recorded IDs/names. Unknown or replaced resources abort before deletion. No global prune or filesystem deletion occurs. The root operator may remove the exact validated /run instance directory after teardown, without printing it. Do not remove any other /run, /etc, /opt, Docker volume or server data.
* Reset/redeploy: teardown the old verified demo, choose a new random instance ID, new credentials and an independently approved release, run prepare/verify/start, then separately approve publish, recreate synthetic accounts/fixtures through normal workflows, repeat acceptance. Do not reuse instance names or retained volumes. The fixed private subnet requires the old demo to be stopped/removed before another starts; conflict detection aborts instead of modifying existing networks.
* Partial startup failure: keep ingress blocked; inspect only sanitized codes. A fresh-start-only controller deliberately refuses existing project containers/volumes. Do not delete random Docker resources to force another start. Revalidate and tear down the exact generated failed demo before preparing a new one. If another retained resource has unexpected labels/mounts, stop and investigate.
* Host reboot or lost /run: secrets and certificate state are lost; no readiness claim survives. Do not generate a new APP_KEY against retained business data. Close demo ingress, inventory only containers/networks/volumes bearing the exact recorded demo project label and confirm tmpfs mounts/no retained data before an operator-approved cleanup. A missing state/manifest prevents the normal teardown controller; fail closed rather than running global prune. Provision a new demo and reissue TLS through approved DNS-01. No recovery guarantee is offered.
* Rollback: keep ingress closed, remove only the failed disposable instance and rebuild a new isolated instance from the last independently approved release and exact images. No reverse migration, ledger rewrite or restore over retained data. Record accepted loss and repeat browser/MFA/security checks before reopening.

Do not describe stopping containers as securely erasing provider RAM or snapshots. Keep any export or diagnostics private; do not enable offsite backup, real providers or customer email as part of teardown.

## Emergency closure without operational dependencies

TLS expiry, deleted TLS files, unavailable GitHub, lost image archives, changed
source files or swap activation must not prevent closure of an already recorded
instance. Target hostname/IP, Linux root, exact /run instance path, private root
state/receipt and live resource identity remain mandatory. No release approval,
certificate, application secret or network GitHub call is required for cleanup:

```sh
python3 scripts/demo/control.py stop --directory "/run/$INSTANCE" --ack "$ACK"
python3 scripts/demo/control.py teardown --directory "/run/$INSTANCE" --ack "$ACK"
```

Keep the root-private resource receipt until teardown completes. If it is lost
or a resource ID/mount differs, automation refuses deletion; an authorized operator
must independently inventory and approve exact resources. Never recover by global
prune or label-only deletion. A stopped instance is not a safe resume point;
create a new instance after accepted data loss.
