#!/usr/bin/env python3
"""
nxtVM vCenter collector — READ-ONLY.

Usage:  python nxtvm_collect.py <mode>     (mode: test | collect)
Input:  JSON on stdin  {"host": "...", "port": 443, "user": "...", "password": "...", "verify_ssl": false}
Output: JSON on stdout {"ok": true, ...} or {"ok": false, "error": "...", "kind": "..."}

Credentials are passed on stdin so they never show up in the process list.
The collector only reads properties and calls query methods; it never changes vCenter state.
(The only objects it creates are session-scoped container views, which it destroys again.)
"""
import base64
import json
import re
import socket
import ssl
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone

from pyVim.connect import Disconnect, SmartConnect
from pyVmomi import vim, vmodl

# Privileges a pure read-only account has on the root folder
READ_ONLY_PRIVS = {"System.Anonymous", "System.Read", "System.View"}

WARNINGS = []   # non-fatal problems, returned to the caller


# --------------------------------------------------------------------------- helpers

def fail(kind, message):
    print(json.dumps({"ok": False, "kind": kind, "error": message}))
    sys.exit(0)


def warn(msg):
    if msg not in WARNINGS:
        WARNINGS.append(msg)


def iso(dt):
    if dt is None:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc).isoformat()


def moid(obj):
    return obj._moId if obj is not None else None


def g(obj, path, default=None):
    """Safe nested attribute access: g(host, 'summary.hardware.vendor')."""
    for part in path.split("."):
        if obj is None:
            return default
        obj = getattr(obj, part, None)
    return default if obj is None else obj


def ssl_context(verify):
    if verify:
        return ssl.create_default_context()
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    return ctx


def pem_from_bytes(raw):
    """vim byte[] (list of signed ints) → str"""
    if not raw:
        return None
    try:
        return bytes((b + 256) % 256 for b in raw).decode("ascii", "replace")
    except TypeError:
        return raw.decode("ascii", "replace") if isinstance(raw, (bytes, bytearray)) else str(raw)


def mask_key(key):
    """License keys are never returned in full: XXXXX-XXXXX-XXXXX-XXXXX-ABCDE"""
    if not key:
        return key
    parts = key.split("-")
    return "-".join(["XXXXX"] * (len(parts) - 1) + [parts[-1]]) if len(parts) > 1 else "XXXXX"


def connect(cfg):
    try:
        return SmartConnect(
            host=cfg["host"],
            port=int(cfg.get("port") or 443),
            user=cfg["user"],
            pwd=cfg["password"],
            sslContext=ssl_context(bool(cfg.get("verify_ssl"))),
            connectionPoolTimeout=int(cfg.get("timeout") or 30),
        )
    except vim.fault.InvalidLogin:
        fail("auth", "Invalid user name or password.")
    except vim.fault.NoPermission as e:
        fail("auth", "No permission: %s" % (e.msg or e))
    except ssl.SSLCertVerificationError as e:
        fail("tls", "Certificate verification failed: %s. Disable 'Verify TLS certificate' for self-signed vCenters." % e.verify_message)
    except ssl.SSLError as e:
        fail("tls", "TLS error: %s" % e)
    except socket.gaierror:
        fail("network", "Host name could not be resolved: %s" % cfg["host"])
    except (socket.timeout, TimeoutError):
        fail("network", "Connection timed out.")
    except ConnectionRefusedError:
        fail("network", "Connection refused on port %s." % (cfg.get("port") or 443))
    except OSError as e:
        fail("network", "Network error: %s" % e)
    except vim.fault.HostConnectFault as e:
        fail("network", e.msg or str(e))
    except Exception as e:  # noqa: BLE001 — report anything else verbatim
        if "not a VIM server" in str(e):
            fail("network", "No vSphere API answered at %s:%s." % (cfg["host"], cfg.get("port") or 443))
        fail("error", "%s: %s" % (type(e).__name__, e))


def retrieve(content, vimtype, props):
    """All objects of a type with the given properties, via one PropertyCollector call (paged)."""
    view = content.viewManager.CreateContainerView(content.rootFolder, [vimtype], True)
    try:
        pc = content.propertyCollector
        spec = vmodl.query.PropertyCollector.FilterSpec(
            objectSet=[vmodl.query.PropertyCollector.ObjectSpec(
                obj=view, skip=True,
                selectSet=[vmodl.query.PropertyCollector.TraversalSpec(name="tv", path="view", skip=False, type=vim.view.ContainerView)],
            )],
            propSet=[vmodl.query.PropertyCollector.PropertySpec(type=vimtype, pathSet=props, all=False)],
        )
        out = []
        res = pc.RetrievePropertiesEx([spec], vmodl.query.PropertyCollector.RetrieveOptions(maxObjects=500))
        while res:
            for o in res.objects:
                d = {p.name: p.val for p in (o.propSet or [])}
                d["_moid"] = o.obj._moId
                d["_obj"] = o.obj
                out.append(d)
            res = pc.ContinueRetrievePropertiesEx(res.token) if res.token else None
        return out
    finally:
        try:
            view.Destroy()
        except Exception:  # noqa: BLE001
            pass


def about_info(content):
    a = content.about
    return {
        "name": a.name, "full_name": a.fullName, "vendor": a.vendor, "version": a.version, "build": a.build,
        "api_type": a.apiType, "api_version": a.apiVersion, "instance_uuid": a.instanceUuid, "os_type": a.osType,
    }


def privileges(content, user_name):
    try:
        res = content.authorizationManager.FetchUserPrivilegeOnEntities([content.rootFolder], user_name)
        privs = sorted(set(res[0].privileges)) if res else []
    except Exception:  # noqa: BLE001
        return None
    return {"count": len(privs), "read_only": set(privs) <= READ_ONLY_PRIVS, "extra": [p for p in privs if p not in READ_ONLY_PRIVS][:20]}


def server_certificate(host, port, timeout=10):
    """The certificate vCenter presents on its HTTPS port (Machine SSL), base64 DER."""
    try:
        with socket.create_connection((host, port), timeout=timeout) as sock:
            with ssl_context(False).wrap_socket(sock, server_hostname=host) as tls:
                der = tls.getpeercert(binary_form=True)
        return {"der_b64": base64.b64encode(der).decode()}
    except Exception:  # noqa: BLE001
        return None


# --------------------------------------------------------------------------- inventory

def build_parents(content):
    """moid → {name, type, parent} for folders, datacenters, compute resources, storage pods."""
    tree = {}
    for t, props in ((vim.Folder, ["name", "parent", "childType"]), (vim.Datacenter, ["name", "parent"]),
                     (vim.ComputeResource, ["name", "parent"]), (vim.StoragePod, ["name", "parent"])):
        for o in retrieve(content, t, props):
            tree[o["_moid"]] = {
                "name": o.get("name"), "parent": moid(o.get("parent")), "type": type(o["_obj"]).__name__.split(".")[-1],
                "child_type": list(o.get("childType") or []),
            }
    return tree


def datacenter_of(tree, mo):
    seen = 0
    while mo and seen < 50:
        node = tree.get(mo)
        if not node:
            return None
        if node["type"] == "Datacenter":
            return mo
        mo = node["parent"]
        seen += 1
    return None


def folder_path(tree, mo):
    """VM folder path below the datacenter's vm folder, e.g. 'Prod/SAP'."""
    parts, seen = [], 0
    while mo and seen < 50:
        node = tree.get(mo)
        if not node or node["type"] != "Folder":
            break
        parent = tree.get(node["parent"])
        if parent and parent["type"] == "Datacenter":
            break  # the datacenter's root "vm" folder itself is not shown
        parts.append(node["name"])
        mo = node["parent"]
        seen += 1
    return "/".join(reversed(parts)) or None


def collect_datacenters(tree):
    return [{"moid": m, "name": n["name"]} for m, n in tree.items() if n["type"] == "Datacenter"]


def collect_clusters(content, tree):
    out = []
    for c in retrieve(content, vim.ClusterComputeResource, ["name", "parent", "configurationEx", "summary", "host"]):
        cfg = c.get("configurationEx")
        s = c.get("summary")
        das = g(cfg, "dasConfig")
        drs = g(cfg, "drsConfig")
        acp = g(das, "admissionControlPolicy")
        rules = []
        for r in g(cfg, "rule", []) or []:
            kind = type(r).__name__.split(".")[-1]
            rules.append({
                "name": r.name, "enabled": bool(r.enabled), "mandatory": bool(getattr(r, "mandatory", False)),
                "type": {"ClusterAntiAffinityRuleSpec": "vm-anti-affinity", "ClusterAffinityRuleSpec": "vm-affinity",
                         "ClusterVmHostRuleInfo": "vm-host", "ClusterDependencyRuleInfo": "dependency"}.get(kind, kind),
                "vms": [moid(v) for v in (getattr(r, "vm", None) or [])],
                "vm_group": getattr(r, "vmGroupName", None),
                "host_group": getattr(r, "affineHostGroupName", None) or getattr(r, "antiAffineHostGroupName", None),
                "anti": bool(getattr(r, "antiAffineHostGroupName", None)),
            })
        groups = []
        for grp in g(cfg, "group", []) or []:
            kind = type(grp).__name__.split(".")[-1]
            groups.append({
                "name": grp.name, "type": "vm" if kind == "ClusterVmGroup" else "host",
                "members": [moid(x) for x in (getattr(grp, "vm", None) or getattr(grp, "host", None) or [])],
            })
        out.append({
            "moid": c["_moid"], "name": c.get("name"), "datacenter": datacenter_of(tree, moid(c.get("parent"))),
            "hosts": [moid(h) for h in c.get("host") or []],
            "ha": {
                "enabled": bool(g(das, "enabled")),
                "admission_control": bool(g(das, "admissionControlEnabled")),
                "admission_policy": type(acp).__name__.split(".")[-1] if acp else None,
                "failover_level": g(acp, "failoverLevel"),
                "cpu_failover_pct": g(acp, "cpuFailoverResourcesPercent"),
                "mem_failover_pct": g(acp, "memoryFailoverResourcesPercent"),
                "host_monitoring": g(das, "hostMonitoring"),
                "vm_monitoring": g(das, "vmMonitoring"),
                "heartbeat_policy": g(das, "hBDatastoreCandidatePolicy"),
            },
            "drs": {
                "enabled": bool(g(drs, "enabled")),
                "behavior": str(g(drs, "defaultVmBehavior")) if g(drs, "defaultVmBehavior") else None,
                "migration_threshold": g(drs, "vmotionRate"),
            },
            "vsan": bool(g(cfg, "vsanConfigInfo.enabled")),
            "evc_mode": g(s, "currentEVCModeKey"),
            "total_cpu_mhz": g(s, "totalCpu"),
            "total_mem_bytes": g(s, "totalMemory"),
            "effective_cpu_mhz": g(s, "effectiveCpu"),
            "effective_mem_mb": g(s, "effectiveMemory"),
            "num_hosts": g(s, "numHosts"),
            "num_effective_hosts": g(s, "numEffectiveHosts"),
            "overall_status": str(g(s, "overallStatus") or ""),
            "rules": rules,
            "groups": groups,
        })
    return out


HOST_PROPS = [
    "name", "parent", "overallStatus",
    "runtime.connectionState", "runtime.powerState", "runtime.inMaintenanceMode", "runtime.bootTime",
    "summary.hardware", "summary.quickStats", "summary.config.product",
    "hardware.systemInfo", "hardware.biosInfo",
    "config.network", "config.dateTimeInfo", "config.service", "config.lockdownMode", "config.certificate",
    "config.storageDevice.multipathInfo", "config.storageDevice.scsiLun", "config.storageDevice.hostBusAdapter",
    "config.powerSystemInfo", "config.hyperThread", "config.adminDisabled",
    "configManager.advancedOption", "datastore", "vm",
]

ADV_OPTIONS = ["Syslog.global.logHost", "ScratchConfig.CurrentScratchLocation", "UserVars.ESXiShellTimeOut",
               "UserVars.ESXiShellInteractiveTimeOut", "Security.AccountLockFailures", "UserVars.SuppressShellWarning"]


def vlan_str(v):
    if v is None:
        return None
    if isinstance(v, int):
        return str(v)
    return str(v)


def host_network(net):
    if not net:
        return {}
    vss = []
    for s in net.vswitch or []:
        vss.append({
            "name": s.name, "mtu": s.mtu, "ports": s.numPorts,
            "uplinks": [p.split("-")[-1] for p in (s.pnic or [])],
            "active_nics": list(g(s, "spec.policy.nicTeaming.nicOrder.activeNic", []) or []),
            "standby_nics": list(g(s, "spec.policy.nicTeaming.nicOrder.standbyNic", []) or []),
            "security": {
                "promiscuous": g(s, "spec.policy.security.allowPromiscuous"),
                "mac_changes": g(s, "spec.policy.security.macChanges"),
                "forged_transmits": g(s, "spec.policy.security.forgedTransmits"),
            },
        })
    pgs = []
    for pg in net.portgroup or []:
        sp = pg.spec
        pgs.append({
            "name": sp.name, "vlan": sp.vlanId, "vswitch": sp.vswitchName,
            "active_nics": list(g(sp, "policy.nicTeaming.nicOrder.activeNic", []) or []),
            "standby_nics": list(g(sp, "policy.nicTeaming.nicOrder.standbyNic", []) or []),
            "security": {
                "promiscuous": g(sp, "policy.security.allowPromiscuous"),
                "mac_changes": g(sp, "policy.security.macChanges"),
                "forged_transmits": g(sp, "policy.security.forgedTransmits"),
            },
        })
    pnics = []
    for p in net.pnic or []:
        pnics.append({
            "device": p.device, "driver": p.driver, "mac": p.mac, "pci": p.pci,
            "speed_mb": g(p, "linkSpeed.speedMb"), "duplex": g(p, "linkSpeed.duplex"),
        })
    vnics = []
    for v in net.vnic or []:
        sp = v.spec
        vnics.append({
            "device": v.device, "portgroup": v.portgroup or None,
            "dvs_uuid": g(sp, "distributedVirtualPort.switchUuid"), "dvpg_key": g(sp, "distributedVirtualPort.portgroupKey"),
            "ip": g(sp, "ip.ipAddress"), "mask": g(sp, "ip.subnetMask"), "dhcp": g(sp, "ip.dhcp"),
            "mac": sp.mac, "mtu": sp.mtu, "stack": g(sp, "netStackInstanceKey"),
        })
    proxy = []
    for p in net.proxySwitch or []:
        proxy.append({"dvs_uuid": p.dvsUuid, "dvs_name": p.dvsName, "mtu": p.mtu, "uplinks": list(p.pnic or [])})
    return {
        "vswitches": vss, "portgroups": pgs, "pnics": pnics, "vmks": vnics, "dvs": proxy,
        "dns": {
            "hostname": g(net, "dnsConfig.hostName"), "domain": g(net, "dnsConfig.domainName"),
            "servers": list(g(net, "dnsConfig.address", []) or []), "search": list(g(net, "dnsConfig.searchDomain", []) or []),
        },
        "gateway": g(net, "ipRouteConfig.defaultGateway"),
    }


def host_storage_paths(h):
    """Per LUN: canonical name, path count, dead paths."""
    luns = {l.key: l for l in (h.get("config.storageDevice.scsiLun") or [])}
    out = []
    for m in g(h.get("config.storageDevice.multipathInfo"), "lun", []) or []:
        lun = luns.get(m.lun)
        if lun is not None and getattr(lun, "lunType", None) not in (None, "disk"):
            continue
        paths = m.path or []
        out.append({
            "canonical": getattr(lun, "canonicalName", None) or m.id,
            "vendor": (getattr(lun, "vendor", "") or "").strip() or None,
            "model": (getattr(lun, "model", "") or "").strip() or None,
            "local": bool(getattr(lun, "localDisk", False)) if hasattr(lun, "localDisk") else None,
            "ssd": getattr(lun, "ssd", None),
            "policy": g(m, "policy.policy"),
            "paths": len(paths),
            "active": sum(1 for p in paths if p.state == "active"),
            "dead": sum(1 for p in paths if p.state == "dead"),
        })
    return out


def host_adv_options(h):
    om = h.get("configManager.advancedOption")
    out = {}
    if om is None:
        return out
    for key in ADV_OPTIONS:
        try:
            vals = om.QueryOptions(key)
            out[key] = vals[0].value if vals else None
        except Exception:  # noqa: BLE001 — option missing on this build or no right to read
            out[key] = None
    return out


def collect_hosts(content, tree, cluster_names):
    out = []
    for h in retrieve(content, vim.HostSystem, HOST_PROPS):
        hw = h.get("summary.hardware")
        qs = h.get("summary.quickStats")
        prod = h.get("summary.config.product")
        si = h.get("hardware.systemInfo")
        parent = moid(h.get("parent"))
        connected = str(h.get("runtime.connectionState")) == "connected"

        serial = g(si, "serialNumber")
        if not serial:
            for info in g(si, "otherIdentifyingInfo", []) or []:
                if g(info, "identifierType.key") in ("ServiceTag", "SerialNumberTag", "EnclosureSerialNumberTag"):
                    serial = info.identifierValue
                    break

        services = [{"key": s.key, "label": s.label, "running": bool(s.running), "policy": s.policy}
                    for s in (g(h.get("config.service"), "service", []) or [])]
        dt = h.get("config.dateTimeInfo")

        out.append({
            "moid": h["_moid"], "name": h.get("name"),
            "cluster": parent if parent in cluster_names else None,
            "datacenter": datacenter_of(tree, parent),
            "overall_status": str(h.get("overallStatus") or ""),
            "connection_state": str(h.get("runtime.connectionState") or ""),
            "power_state": str(h.get("runtime.powerState") or ""),
            "maintenance": bool(h.get("runtime.inMaintenanceMode")),
            "boot_time": iso(h.get("runtime.bootTime")),
            "vendor": g(hw, "vendor"), "model": g(hw, "model"), "serial": serial, "uuid": g(hw, "uuid"),
            "bios": {"version": g(h.get("hardware.biosInfo"), "biosVersion"), "date": iso(g(h.get("hardware.biosInfo"), "releaseDate"))},
            "cpu_model": g(hw, "cpuModel"), "cpu_sockets": g(hw, "numCpuPkgs"), "cpu_cores": g(hw, "numCpuCores"),
            "cpu_threads": g(hw, "numCpuThreads"), "cpu_mhz": g(hw, "cpuMhz"), "mem_bytes": g(hw, "memorySize"),
            "nics": g(hw, "numNics"), "hbas": g(hw, "numHBAs"),
            "hyperthreading": g(h.get("config.hyperThread"), "active"),
            "version": g(prod, "version"), "build": g(prod, "build"), "full_name": g(prod, "fullName"),
            "cpu_usage_mhz": g(qs, "overallCpuUsage"), "mem_usage_mb": g(qs, "overallMemoryUsage"), "uptime_s": g(qs, "uptime"),
            "lockdown": str(h.get("config.lockdownMode") or "") or None,
            "power_policy": g(h.get("config.powerSystemInfo"), "currentPolicy.shortName"),
            "ntp": {
                "servers": list(g(dt, "ntpConfig.server", []) or []),
                "timezone": g(dt, "timeZone.name"),
            },
            "services": services,
            "advanced": host_adv_options(h) if connected else {},
            "network": host_network(h.get("config.network")),
            "storage_paths": host_storage_paths(h),
            "hba": [{"device": a.device, "model": a.model, "driver": a.driver, "status": a.status,
                     "type": type(a).__name__.split(".")[-1].replace("Host", "").replace("HostBusAdapter", ""),
                     "wwpn": format(a.portWorldWideName, "x") if getattr(a, "portWorldWideName", None) else None}
                    for a in (h.get("config.storageDevice.hostBusAdapter") or [])],
            "certificate_pem": pem_from_bytes(h.get("config.certificate")),
            "datastores": [moid(d) for d in h.get("datastore") or []],
            "vm_count": len(h.get("vm") or []),
        })
    return out


VM_PROPS = [
    "name", "parent", "resourcePool", "overallStatus",
    "config.template", "config.uuid", "config.instanceUuid", "config.guestId", "config.guestFullName", "config.version",
    "config.hardware.numCPU", "config.hardware.numCoresPerSocket", "config.hardware.memoryMB", "config.hardware.device",
    "config.cpuAllocation", "config.memoryAllocation", "config.annotation", "config.cpuHotAddEnabled",
    "config.memoryHotAddEnabled", "config.firmware", "config.files.vmPathName", "config.createDate",
    "config.bootOptions.efiSecureBootEnabled",
    "runtime.powerState", "runtime.connectionState", "runtime.host", "runtime.bootTime", "runtime.consolidationNeeded",
    "guest.toolsRunningStatus", "guest.toolsVersionStatus2", "guest.toolsVersion", "guest.guestFullName",
    "guest.hostName", "guest.ipAddress", "guest.net", "guest.disk", "guest.guestState",
    "summary.storage", "snapshot", "layoutEx.file", "datastore",
]

SNAP_FILE = re.compile(r"-\d{6}(-delta|-sesparse)?\.vmdk$")


def flatten_snapshots(nodes, parent=None, out=None):
    out = [] if out is None else out
    for n in nodes or []:
        out.append({"name": n.name, "description": n.description or None, "created": iso(n.createTime),
                    "id": n.id, "parent": parent, "quiesced": bool(n.quiesced), "memory": str(n.state) == "poweredOn"})
        flatten_snapshots(n.childSnapshotList, n.id, out)
    return out


def vm_devices(devices, ds_names, net_names, dvpg_names):
    disks, nics, cdroms, ctrls, other = [], [], [], [], []
    for d in devices or []:
        kind = type(d).__name__.split(".")[-1]
        label = g(d, "deviceInfo.label")
        if isinstance(d, vim.vm.device.VirtualDisk):
            b = d.backing
            bkind = type(b).__name__.split(".")[-1]
            disks.append({
                "label": label, "capacity_bytes": d.capacityInBytes, "file": g(b, "fileName"),
                "datastore": moid(g(b, "datastore")),
                "thin": g(b, "thinProvisioned"), "eager": g(b, "eagerlyScrub"), "mode": g(b, "diskMode"),
                "rdm": g(b, "compatibilityMode") if "RawDiskMapping" in bkind else None,
                "sharing": g(b, "sharing"),
                "controller": d.controllerKey, "unit": d.unitNumber,
            })
        elif isinstance(d, vim.vm.device.VirtualEthernetCard):
            b = d.backing
            net = None
            if isinstance(b, vim.vm.device.VirtualEthernetCard.DistributedVirtualPortBackingInfo):
                net = dvpg_names.get(g(b, "port.portgroupKey"))
            elif g(b, "network") is not None:
                net = net_names.get(moid(b.network)) or g(b, "deviceName")
            else:
                net = g(b, "deviceName")
            nics.append({
                "label": label, "type": kind.replace("Virtual", ""), "mac": d.macAddress, "network": net,
                "connected": g(d, "connectable.connected"), "start_connected": g(d, "connectable.startConnected"),
            })
        elif isinstance(d, vim.vm.device.VirtualCdrom):
            cdroms.append({"label": label, "connected": g(d, "connectable.connected"),
                           "start_connected": g(d, "connectable.startConnected"),
                           "iso": g(d, "backing.fileName")})
        elif isinstance(d, vim.vm.device.VirtualController) and "Controller" in kind and not isinstance(
                d, (vim.vm.device.VirtualIDEController, vim.vm.device.VirtualPCIController, vim.vm.device.VirtualPS2Controller,
                    vim.vm.device.VirtualSIOController)):
            ctrls.append({"key": d.key, "type": kind.replace("Virtual", ""), "bus": getattr(d, "busNumber", None),
                          "sharing": str(getattr(d, "sharedBus", "") or "") or None})
        elif isinstance(d, (vim.vm.device.VirtualFloppy, vim.vm.device.VirtualUSB, vim.vm.device.VirtualSerialPort,
                            vim.vm.device.VirtualParallelPort)):
            other.append({"label": label, "type": kind.replace("Virtual", ""), "connected": g(d, "connectable.connected")})
    return disks, nics, cdroms, ctrls, other


def collect_vms(content, tree, host_map, ds_names, net_names, dvpg_names):
    out = []
    for v in retrieve(content, vim.VirtualMachine, VM_PROPS):
        host = moid(v.get("runtime.host"))
        disks, nics, cdroms, ctrls, other = vm_devices(v.get("config.hardware.device"), ds_names, net_names, dvpg_names)
        snaps = flatten_snapshots(g(v.get("snapshot"), "rootSnapshotList"))
        snap_bytes = 0
        if snaps:
            for f in v.get("layoutEx.file") or []:
                if f.type in ("snapshotData", "snapshotMemory") or (f.type == "diskExtent" and SNAP_FILE.search(f.name or "")):
                    snap_bytes += f.size or 0
        st = v.get("summary.storage")
        cpu_a, mem_a = v.get("config.cpuAllocation"), v.get("config.memoryAllocation")
        out.append({
            "moid": v["_moid"], "name": v.get("name"),
            "host": host, "cluster": host_map.get(host, {}).get("cluster"),
            "datacenter": datacenter_of(tree, moid(v.get("parent"))),
            "folder": folder_path(tree, moid(v.get("parent"))),
            "template": bool(v.get("config.template")),
            "uuid": v.get("config.uuid"), "instance_uuid": v.get("config.instanceUuid"),
            "overall_status": str(v.get("overallStatus") or ""),
            "power_state": str(v.get("runtime.powerState") or ""),
            "connection_state": str(v.get("runtime.connectionState") or ""),
            "boot_time": iso(v.get("runtime.bootTime")),
            "created": iso(v.get("config.createDate")),
            "consolidation_needed": bool(v.get("runtime.consolidationNeeded")),
            "guest_id": v.get("config.guestId"),
            "guest_os": v.get("guest.guestFullName") or v.get("config.guestFullName"),
            "guest_os_config": v.get("config.guestFullName"),
            "hw_version": v.get("config.version"),
            "firmware": v.get("config.firmware"),
            "secure_boot": v.get("config.bootOptions.efiSecureBootEnabled"),
            "vcpu": v.get("config.hardware.numCPU"), "cores_per_socket": v.get("config.hardware.numCoresPerSocket"),
            "mem_mb": v.get("config.hardware.memoryMB"),
            "cpu_hot_add": v.get("config.cpuHotAddEnabled"), "mem_hot_add": v.get("config.memoryHotAddEnabled"),
            "cpu_reservation": g(cpu_a, "reservation"), "cpu_limit": g(cpu_a, "limit"),
            "mem_reservation": g(mem_a, "reservation"), "mem_limit": g(mem_a, "limit"),
            "tools_running": v.get("guest.toolsRunningStatus"), "tools_status": v.get("guest.toolsVersionStatus2"),
            "tools_version": v.get("guest.toolsVersion"),
            "guest_state": v.get("guest.guestState"),
            "hostname": v.get("guest.hostName"), "ip": v.get("guest.ipAddress"),
            "ips": sorted({ip for n in (v.get("guest.net") or []) for ip in (n.ipAddress or []) if ":" not in ip}),
            "guest_disks": [{"path": d.diskPath, "capacity": d.capacity, "free": d.freeSpace} for d in (v.get("guest.disk") or [])],
            "committed_bytes": g(st, "committed"), "uncommitted_bytes": g(st, "uncommitted"),
            "vmx": v.get("config.files.vmPathName"),
            "annotation": (v.get("config.annotation") or "")[:500] or None,
            "datastores": [moid(d) for d in v.get("datastore") or []],
            "disks": disks, "nics": nics, "cdroms": cdroms, "controllers": ctrls, "other_devices": other,
            "snapshots": snaps, "snapshot_bytes": snap_bytes,
        })
    return out


def collect_datastores(content, tree):
    out = []
    for d in retrieve(content, vim.Datastore, ["name", "parent", "summary", "host", "info", "vm", "overallStatus"]):
        s = d.get("summary")
        info = d.get("info")
        vmfs = g(info, "vmfs")
        parent = d.get("parent")
        cap, free, unc = g(s, "capacity", 0), g(s, "freeSpace", 0), g(s, "uncommitted", 0) or 0
        out.append({
            "moid": d["_moid"], "name": d.get("name"),
            "datacenter": datacenter_of(tree, moid(parent)),
            "cluster": tree.get(moid(parent), {}).get("name") if isinstance(parent, vim.StoragePod) else None,
            "type": g(s, "type"), "url": g(s, "url"),
            "capacity_bytes": cap, "free_bytes": free, "uncommitted_bytes": unc,
            "provisioned_bytes": cap - free + unc,
            "accessible": bool(g(s, "accessible")), "multiple_host_access": g(s, "multipleHostAccess"),
            "maintenance": g(s, "maintenanceMode"),
            "overall_status": str(d.get("overallStatus") or ""),
            "vmfs": {
                "version": g(vmfs, "version"), "ssd": g(vmfs, "ssd"), "local": g(vmfs, "local"),
                "block_mb": g(vmfs, "blockSizeMb"),
                "extents": [e.diskName for e in (g(vmfs, "extent", []) or [])],
            } if vmfs else None,
            "nas": {"server": g(info, "nas.remoteHost"), "path": g(info, "nas.remotePath"), "type": g(info, "nas.type")} if g(info, "nas") else None,
            "hosts": [{"host": moid(m.key), "mounted": g(m, "mountInfo.mounted"), "accessible": g(m, "mountInfo.accessible"),
                       "mode": g(m, "mountInfo.accessMode")} for m in (d.get("host") or [])],
            "vm_count": len(d.get("vm") or []),
        })
    return out


def vlan_of_dvpg(cfg):
    v = g(cfg, "defaultPortConfig.vlan")
    if v is None:
        return None, None
    kind = type(v).__name__.split(".")[-1]
    if "Trunk" in kind:
        ranges = ["%d-%d" % (r.start, r.end) if r.start != r.end else str(r.start) for r in (v.vlanId or [])]
        return "trunk", ",".join(ranges)
    if "Pvlan" in kind:
        return "pvlan", str(v.pvlanId)
    return "vlan", str(v.vlanId)


def collect_networks(content):
    dvs_list, dvpgs, nets = [], [], []
    for s in retrieve(content, vim.DistributedVirtualSwitch, ["name", "uuid", "config", "summary.productInfo", "portgroup"]):
        cfg = s.get("config")
        dvs_list.append({
            "moid": s["_moid"], "name": s.get("name"), "uuid": s.get("uuid"),
            "version": g(s.get("summary.productInfo"), "version"),
            "mtu": g(cfg, "maxMtu"),
            "uplinks": list(g(cfg, "uplinkPortPolicy.uplinkPortName", []) or []),
            "lacp": [{"name": l.name, "mode": l.mode, "uplinks": l.uplinkNum} for l in (g(cfg, "lacpGroupConfig", []) or [])],
            "hosts": [{"host": moid(g(m, "config.host")),
                       "pnics": [p.pnicDevice for p in (g(m, "config.backing.pnicSpec", []) or [])]}
                      for m in (g(cfg, "host", []) or [])],
            "nioc": g(cfg, "networkResourceManagementEnabled"),
        })
    for p in retrieve(content, vim.dvs.DistributedVirtualPortgroup, ["name", "key", "config", "host", "vm"]):
        cfg = p.get("config")
        vtype, vlan = vlan_of_dvpg(cfg)
        teaming = g(cfg, "defaultPortConfig.uplinkTeamingPolicy")
        dvpgs.append({
            "moid": p["_moid"], "key": p.get("key"), "name": p.get("name"),
            "dvs": moid(g(cfg, "distributedVirtualSwitch")),
            "uplink": bool(g(cfg, "uplink")),
            "vlan_type": vtype, "vlan": vlan,
            "ports": g(cfg, "numPorts"), "binding": g(cfg, "type"),
            "teaming": g(teaming, "policy.value"),
            "active_uplinks": list(g(teaming, "uplinkPortOrder.activeUplinkPort", []) or []),
            "standby_uplinks": list(g(teaming, "uplinkPortOrder.standbyUplinkPort", []) or []),
            "hosts": [moid(h) for h in p.get("host") or []],
            "vm_count": len(p.get("vm") or []),
        })
    dvpg_moids = {d["moid"] for d in dvpgs}
    for n in retrieve(content, vim.Network, ["name", "host", "vm"]):
        if n["_moid"] in dvpg_moids or isinstance(n["_obj"], vim.dvs.DistributedVirtualPortgroup):
            continue
        nets.append({"moid": n["_moid"], "name": n.get("name"), "hosts": [moid(h) for h in n.get("host") or []],
                     "vm_count": len(n.get("vm") or [])})
    return dvs_list, dvpgs, nets


def collect_licenses(content, names):
    lm = content.licenseManager
    out, assignments = [], []
    try:
        for lic in lm.licenses or []:
            props = {p.key: p.value for p in (lic.properties or []) if isinstance(p.value, (str, int, float, bool, datetime))}
            exp = props.get("expirationDate")
            out.append({
                "key": mask_key(lic.licenseKey), "edition": lic.editionKey, "name": lic.name,
                "total": lic.total, "used": lic.used, "unit": lic.costUnit,
                "expires": iso(exp) if isinstance(exp, datetime) else None,
                "product": props.get("ProductName"), "product_version": props.get("ProductVersion"),
                "labels": [{"key": l.key, "value": l.value} for l in (lic.labels or [])],
            })
    except vim.fault.NoPermission:
        warn("Licenses: no permission to read.")
    try:
        for a in lm.licenseAssignmentManager.QueryAssignedLicenses() or []:
            assignments.append({
                "entity": a.entityId, "name": a.entityDisplayName or names.get(a.entityId),
                "key": mask_key(g(a, "assignedLicense.licenseKey")), "license_name": g(a, "assignedLicense.name"),
                "scope": g(a, "scope"),
            })
    except Exception as e:  # noqa: BLE001
        warn("License assignments: %s" % (getattr(e, "msg", None) or type(e).__name__))
    eval_info = None
    try:
        ev = lm.evaluation
        if ev:
            eval_info = {p.key: (iso(p.value) if isinstance(p.value, datetime) else p.value)
                         for p in (ev.properties or []) if isinstance(p.value, (str, int, datetime))}
    except Exception:  # noqa: BLE001
        pass
    return out, assignments, eval_info


def collect_alarms(content, names):
    out = []
    alarm_names = {}
    try:
        states = content.rootFolder.triggeredAlarmState or []
    except Exception:  # noqa: BLE001
        return out
    for s in states:
        am = moid(s.alarm)
        if am not in alarm_names:
            try:
                alarm_names[am] = s.alarm.info.name
            except Exception:  # noqa: BLE001
                alarm_names[am] = am
        em = moid(s.entity)
        out.append({
            "alarm": alarm_names[am], "entity": em, "entity_name": names.get(em),
            "entity_type": type(s.entity).__name__.split(".")[-1],
            "status": str(s.overallStatus), "time": iso(s.time), "acknowledged": bool(s.acknowledged),
        })
    return out


VC_SETTINGS = ["VirtualCenter.FQDN", "VirtualCenter.InstanceName", "event.maxAge", "event.maxAgeEnabled", "task.maxAge",
               "task.maxAgeEnabled", "mail.smtp.server", "mail.sender", "snmp.receiver.1.enabled", "snmp.receiver.1.name",
               "config.log.level"]


def collect_vc_settings(content):
    out = {}
    try:
        for key in VC_SETTINGS:
            try:
                vals = content.setting.QueryOptions(key)
                out[key] = vals[0].value if vals else None
            except Exception:  # noqa: BLE001
                out[key] = None
    except Exception:  # noqa: BLE001
        pass
    return out


# --------------------------------------------------------------------------- appliance REST (best effort)

REST_ENDPOINTS = {
    "version": "/api/appliance/system/version",
    "uptime": "/api/appliance/system/uptime",
    "health_system": "/api/appliance/health/system",
    "health_load": "/api/appliance/health/load",
    "health_mem": "/api/appliance/health/mem",
    "health_storage": "/api/appliance/health/storage",
    "health_swap": "/api/appliance/health/swap",
    "health_db": "/api/appliance/health/database-storage",
    "health_services": "/api/appliance/health/applmgmt",
    "health_packages": "/api/appliance/health/software-packages",
    "networking": "/api/appliance/networking",
    "dns": "/api/appliance/networking/dns/servers",
    "ntp": "/api/appliance/ntp",
    "timesync": "/api/appliance/timesync",
    "ssh": "/api/appliance/access/ssh",
    "shell": "/api/appliance/access/shell",
    "backup_schedules": "/api/appliance/recovery/backup/schedules",
    "backup_jobs": "/api/appliance/recovery/backup/job/details",
    "tls": "/api/vcenter/certificate-management/vcenter/tls",
    "root_chains": "/api/vcenter/certificate-management/vcenter/trusted-root-chains",
    "storage": "/api/appliance/system/storage",
}


def rest_appliance(cfg):
    base = "https://%s:%s" % (cfg["host"], int(cfg.get("port") or 443))
    ctx = ssl_context(bool(cfg.get("verify_ssl")))
    out = {"available": False, "endpoints": {}}
    try:
        auth = base64.b64encode(("%s:%s" % (cfg["user"], cfg["password"])).encode()).decode()
        req = urllib.request.Request(base + "/api/session", method="POST", headers={"Authorization": "Basic " + auth})
        with urllib.request.urlopen(req, context=ctx, timeout=15) as r:
            token = json.loads(r.read().decode())
    except Exception as e:  # noqa: BLE001
        out["error"] = "REST session: %s" % e
        return out
    out["available"] = True
    headers = {"vmware-api-session-id": token, "Accept": "application/json"}
    for name, path in REST_ENDPOINTS.items():
        try:
            with urllib.request.urlopen(urllib.request.Request(base + path, headers=headers), context=ctx, timeout=15) as r:
                out["endpoints"][name] = {"ok": True, "data": json.loads(r.read().decode() or "null")}
        except urllib.error.HTTPError as e:
            out["endpoints"][name] = {"ok": False, "status": e.code}
        except Exception as e:  # noqa: BLE001
            out["endpoints"][name] = {"ok": False, "error": str(e)[:200]}
    # trusted root chain details (one call per chain)
    rc = out["endpoints"].get("root_chains")
    if rc and rc.get("ok") and isinstance(rc.get("data"), list):
        chains = []
        for c in rc["data"][:20]:
            cid = c.get("chain") if isinstance(c, dict) else c
            try:
                with urllib.request.urlopen(urllib.request.Request(
                        base + "/api/vcenter/certificate-management/vcenter/trusted-root-chains/" + urllib.request.quote(str(cid)),
                        headers=headers), context=ctx, timeout=15) as r:
                    d = json.loads(r.read().decode())
                    chains.append({"id": cid, "pem": (d.get("cert_chain") or {}).get("cert_chain", [])})
            except Exception:  # noqa: BLE001
                chains.append({"id": cid, "pem": []})
        out["root_chain_pems"] = chains
    try:
        urllib.request.urlopen(urllib.request.Request(base + "/api/session", method="DELETE", headers=headers), context=ctx, timeout=10)
    except Exception:  # noqa: BLE001
        pass
    return out


# --------------------------------------------------------------------------- modes

def mode_test(cfg):
    started = time.time()
    si = connect(cfg)
    try:
        content = si.RetrieveContent()
        session = content.sessionManager.currentSession
        user_name = session.userName if session else cfg["user"]
        dcs = [e for e in content.rootFolder.childEntity if isinstance(e, vim.Datacenter)]
        out = {
            "ok": True, "about": about_info(content), "user": user_name, "server_time": iso(si.CurrentTime()),
            "datacenters": len(dcs), "privileges": privileges(content, user_name),
            "certificate": server_certificate(cfg["host"], int(cfg.get("port") or 443)),
            "elapsed_ms": int((time.time() - started) * 1000),
        }
        if out["about"]["api_type"] != "VirtualCenter":
            out["warning"] = "This is a standalone ESXi host, not a vCenter Server."
        print(json.dumps(out))
    finally:
        Disconnect(si)


def timed(timings, name, fn, *args):
    t = time.time()
    try:
        return fn(*args)
    finally:
        timings[name] = int((time.time() - t) * 1000)


def mode_collect(cfg):
    started = time.time()
    timings = {}
    si = connect(cfg)
    try:
        content = si.RetrieveContent()
        session = content.sessionManager.currentSession
        user_name = session.userName if session else cfg["user"]

        tree = timed(timings, "tree", build_parents, content)
        datacenters = collect_datacenters(tree)
        clusters = timed(timings, "clusters", collect_clusters, content, tree)
        cluster_names = {c["moid"]: c["name"] for c in clusters}
        hosts = timed(timings, "hosts", collect_hosts, content, tree, cluster_names)
        host_map = {h["moid"]: h for h in hosts}
        datastores = timed(timings, "datastores", collect_datastores, content, tree)
        dvs, dvpgs, nets = timed(timings, "networks", collect_networks, content)
        vms = timed(timings, "vms", collect_vms, content, tree, host_map,
                    {d["moid"]: d["name"] for d in datastores},
                    {n["moid"]: n["name"] for n in nets},
                    {p["key"]: p["name"] for p in dvpgs})

        names = {}
        for coll in (hosts, vms, datastores, clusters, datacenters, dvs, dvpgs, nets):
            for o in coll:
                names[o["moid"]] = o["name"]
        licenses, assignments, evaluation = timed(timings, "licenses", collect_licenses, content, names)
        alarms = timed(timings, "alarms", collect_alarms, content, names)
        settings = timed(timings, "settings", collect_vc_settings, content)
        server_time = iso(si.CurrentTime())
        about = about_info(content)
    finally:
        Disconnect(si)

    appliance = timed(timings, "rest", rest_appliance, cfg)

    print(json.dumps({
        "ok": True,
        "collected_at": server_time,
        "about": about,
        "user": user_name,
        "certificate": server_certificate(cfg["host"], int(cfg.get("port") or 443)),
        "datacenters": datacenters,
        "clusters": clusters,
        "hosts": hosts,
        "vms": vms,
        "datastores": datastores,
        "dvswitches": dvs,
        "dvportgroups": dvpgs,
        "networks": nets,
        "licenses": licenses,
        "license_assignments": assignments,
        "license_evaluation": evaluation,
        "alarms": alarms,
        "settings": settings,
        "appliance": appliance,
        "warnings": WARNINGS,
        "timings_ms": timings,
        "elapsed_ms": int((time.time() - started) * 1000),
    }, default=str))


def main():
    modes = {"test": mode_test, "collect": mode_collect}
    if len(sys.argv) != 2 or sys.argv[1] not in modes:
        fail("usage", "usage: nxtvm_collect.py test|collect")
    try:
        cfg = json.load(sys.stdin)
    except ValueError:
        fail("usage", "Invalid JSON on stdin.")
    for key in ("host", "user", "password"):
        if not cfg.get(key):
            fail("usage", "Missing '%s'." % key)
    socket.setdefaulttimeout(int(cfg.get("timeout") or 30))
    modes[sys.argv[1]](cfg)


if __name__ == "__main__":
    main()
