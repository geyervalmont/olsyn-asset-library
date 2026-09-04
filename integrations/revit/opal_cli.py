#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
The Revit extension's operations from a Unix shell, against a JSON project
and a mounted OPAL drive. Same code the ribbon buttons run.

  opal_cli.py --api https://asset-library.test --token opal_... --mount /mnt/opal --drive studio-share \
      sync   --project project.json
      plan   --project project.json --material <host id> --variant CPT-TARKETT-ACADEMIX-ASHEN
      apply  --project project.json --material <host id> --variant CPT-TARKETT-ACADEMIX-ASHEN
      check  --variant CPT-TARKETT-ACADEMIX-ASHEN
      seed   --project project.json --name "Carpet - Academix Ashen" [--parameter Description="..."]
      link   [--no-browser]            link this machine to an OPAL account; saves the config
      agent  --project project.json    stay connected and execute commands sent from the web app

Saved config (~/.config/opal/config.json, or OPAL_CONFIG) supplies defaults for
--api, --token, --drive and --mount after `link`.
"""

import argparse
import os
import socket
import sys
import webbrowser

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from opal_client import ApiError, Agent, Config, LinkExpired, OpalApi, Drive, UnixHost, Workflow, link  # noqa: E402


def main(argv=None):
    config = Config()
    saved = config.load()
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--api", default=os.environ.get("OPAL_API") or saved.get("api") or "https://asset-library.test")
    parser.add_argument("--token", default=os.environ.get("OPAL_TOKEN") or saved.get("token"))
    parser.add_argument("--mount", default=os.environ.get("OPAL_MOUNT") or saved.get("mount"))
    parser.add_argument("--drive", default=os.environ.get("OPAL_DRIVE") or saved.get("drive") or "studio-share")
    parser.add_argument("--insecure", action="store_true", default=saved.get("verify_tls") is False, help="skip TLS verification (local dev certificates)")
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("sync"); p.add_argument("--project", required=True)
    p = sub.add_parser("plan"); p.add_argument("--project", required=True); p.add_argument("--material", required=True); p.add_argument("--variant", required=True); p.add_argument("--quality")
    p = sub.add_parser("apply"); p.add_argument("--project", required=True); p.add_argument("--material", required=True); p.add_argument("--variant", required=True); p.add_argument("--quality")
    p = sub.add_parser("check"); p.add_argument("--variant", required=True)
    p = sub.add_parser("seed"); p.add_argument("--project", required=True); p.add_argument("--name", required=True); p.add_argument("--id"); p.add_argument("--parameter", action="append", default=[])
    p = sub.add_parser("link"); p.add_argument("--no-browser", action="store_true"); p.add_argument("--machine", default=socket.gethostname())
    p = sub.add_parser("agent"); p.add_argument("--project", required=True); p.add_argument("--machine", default=socket.gethostname())

    args = parser.parse_args(argv)

    if args.command == "link":
        try:
            result = link(args.api, "unix", machine=args.machine, app_version="opal-cli/1.0",
                          open_browser=None if args.no_browser else webbrowser.open, verify_tls=not args.insecure, log=print)
        except LinkExpired as error:
            print(error)
            return 2
        except ApiError as error:
            if error.status == 429:
                print("the link endpoint is rate limited; wait a minute and try again")
                return 2
            raise
        config.update(api=args.api, token=result["token"], realtime=result["realtime"], user=result["user"],
                      drive=args.drive, mount=args.mount, verify_tls=not args.insecure)
        print("linked as %s; config saved to %s" % ((result["user"] or {}).get("email", "?"), config.path))
        return 0

    if not args.token:
        parser.error("--token or OPAL_TOKEN is required (or run `link` first)")

    api = OpalApi(args.api, args.token, verify_tls=not args.insecure)
    drives = dict((d["slug"], d) for d in api.drives())
    if args.drive not in drives:
        parser.error("unknown drive %s; available: %s" % (args.drive, ", ".join(sorted(drives))))
    drive = Drive(args.drive, args.mount or "", drives[args.drive]["root_path"])

    if args.command == "seed":
        host = UnixHost(args.project)
        parameters = dict(item.split("=", 1) for item in args.parameter)
        host_id = args.id or "mat-%d" % (len(host.materials()) + 1)
        host.add_material(host_id, args.name, parameters)
        host.select(host_id)
        print("added %s (%s) to %s" % (args.name, host_id, args.project))
        return 0

    if args.command == "check":
        paths = api.variant_paths(args.variant, args.drive)
        print("%s on %s (published: %s, mounted: %s)" % (paths["variant"], args.drive, paths["published"], drive.mounted()))
        for entry in drive.check(paths["files"]):
            state = "ok" if entry["hash_matches"] else ("present" if entry["present"] else "MISSING")
            print("  %-8s %-10s %-4s %s" % (state, entry["target"], entry["quality"], entry["local_path"]))
        return 0

    host = UnixHost(args.project)
    workflow = Workflow(api, host, drive)

    if args.command == "agent":
        agent = Agent(api, host, drive, realtime=saved.get("realtime"), machine=args.machine, app_version="opal-cli/1.0", log=print)
        print("agent: %s on drive %s at %s; Ctrl-C to stop" % (args.project, args.drive, args.mount))
        try:
            agent.run()
        except KeyboardInterrupt:
            agent.stop()
            print("agent: stopped")
        return 0

    if args.command == "sync":
        report = workflow.sync()
        for material, variant, reference in report.matched:
            print("  matched   %-40s → %s  (via %s)" % (material.name, variant["code"], reference))
        for material in report.unmatched:
            print("  unmatched %s" % material.name)
        print(report.summary())
        return 0

    material = host.material(args.material)
    if material is None:
        parser.error("no material %s in %s" % (args.material, args.project))

    plan = workflow.plan(material, args.variant, args.quality)
    print(plan.describe())

    if args.command == "apply":
        if not plan.ready():
            return 2
        identity = workflow.apply(plan)
        print("applied; identity registered: %s → %s (%s)" % (identity["external_id"], identity["variant"], identity["platform"]))
    return 0


if __name__ == "__main__":
    sys.exit(main())
