#!/usr/bin/env python3
"""Read-only Phase 8B representation review checker. Never authorizes inference."""
from __future__ import annotations
import argparse,csv,datetime as dt,hashlib,json,sys
from pathlib import Path

EXPECTED_AUDIT_SHA256 = "4a5eab84efb70cfe640e10bd88d3354b941193df3b71df4f3913cc9c5cac6c10"
FIELDNAMES = ["issue_id","priority","redaction_version","affected_pair_ids","source_snapshot_sha256","decision","proposed_minimal_change","evidence_reference","rights_privacy_reviewed","reviewer_name","reviewed_at","reason"]
DECISIONS = {"PENDING", "REQUEST_REVISION", "RETAIN_WITH_RISK", "DEFER"}

def load_csv(path: Path):
    with path.open(newline="",encoding="utf-8-sig") as f:
        reader=csv.DictReader(f)
        if reader.fieldnames is None: raise ValueError(f"Empty CSV: {path.name}")
        if len(reader.fieldnames)!=len(set(reader.fieldnames)): raise ValueError(f"Duplicate columns: {path.name}")
        rows=list(reader)
        if any(None in r for r in rows): raise ValueError(f"Unexpected extra cells: {path.name}")
        return reader.fieldnames,rows

def expected(audit):
    rows=[r for r in audit if r.get('code_log_environment_removed')=='True']
    if len(rows)!=13: raise ValueError('Source must contain exactly 13 flagged Issues')
    count_high=sum(r['severity']=='REVISION_RECOMMENDED_BEFORE_INFERENCE' for r in rows)
    if count_high!=5: raise ValueError('Expected exactly 5 high-priority Issues')
    pairs=set(x for r in rows for x in r['affected_pair_ids'].split(';'))
    highpairs=set(x for r in rows if r['severity']=='REVISION_RECOMMENDED_BEFORE_INFERENCE' for x in r['affected_pair_ids'].split(';'))
    if len(pairs)!=21 or len(highpairs)!=11: raise ValueError('Frozen issue/pair linkage mismatch')
    return {r['issue_id']:r for r in rows}

def validate(review, audit):
    expected_rows=expected(audit)
    issues=[]
    seen=set()
    pending=[]
    complete=[]
    now=dt.datetime.now(dt.timezone.utc)
    for r in review:
        ident=(r.get('issue_id') or '').strip()
        if ident in seen: issues.append(f'{ident}: duplicate Issue row'); continue
        seen.add(ident)
        a=expected_rows.get(ident)
        if a is None: issues.append(f'{ident}: not among frozen 13 flagged Issues'); continue
        static={
          'priority': 'HIGH' if a['severity']=='REVISION_RECOMMENDED_BEFORE_INFERENCE' else 'MODERATE',
          'redaction_version':a['redaction_version'],
          'affected_pair_ids':a['affected_pair_ids'],
          'source_snapshot_sha256':a['snapshot_sha256'],
        }
        for col,valid in static.items():
            if r.get(col)!=valid: issues.append(f'{ident}: immutable {col} changed')
        decision=(r.get('decision') or '').strip()
        if decision not in DECISIONS:
            issues.append(f'{ident}: unknown decision {decision!r}')
            continue
        if decision=='PENDING':
            if any((r.get(col) or '').strip() for col in ('proposed_minimal_change','evidence_reference','rights_privacy_reviewed','reviewer_name','reviewed_at','reason')):
                issues.append(f'{ident}: partial fields entered while decision=PENDING')
            pending.append(ident)
            continue
        needed=['evidence_reference','rights_privacy_reviewed','reviewer_name','reviewed_at','reason']
        if decision=='REQUEST_REVISION': needed.append('proposed_minimal_change')
        for col in needed:
            if not (r.get(col) or '').strip(): issues.append(f'{ident}: missing {col}')
        if (r.get('rights_privacy_reviewed') or '').strip() not in ('YES','NO'):
            issues.append(f'{ident}: rights_privacy_reviewed must be YES or NO')
        if decision in ('REQUEST_REVISION','RETAIN_WITH_RISK') and r.get('rights_privacy_reviewed')!='YES':
            issues.append(f'{ident}: revision/retention requires privacy review YES')
        timestamp=(r.get('reviewed_at') or '').strip()
        if timestamp:
            try:
                if 'T' not in timestamp or len(timestamp.split('T',1)[1].split(':'))<3: raise ValueError('seconds required')
                d=dt.datetime.fromisoformat(timestamp.replace('Z','+00:00'))
                if d.utcoffset() is None: raise ValueError('timezone required')
                if d.astimezone(dt.timezone.utc)>now: raise ValueError('future timestamp')
            except ValueError as e: issues.append(f'{ident}: invalid reviewed_at ({e})')
        complete.append(ident)
    if set(expected_rows)!=seen:
        issues.append('Frozen issue set mismatch: missing '+','.join(sorted(set(expected_rows)-seen)))
    if issues: state='INVALID_REVIEW_PACKAGE'
    elif pending: state='REVIEW_INCOMPLETE'
    else: state='REVIEW_RECORDED_ONLY'
    return {'status':state,'rows_total':len(review),'decisions_recorded':len(complete),'pending':len(pending),'validation_errors':issues,'execution_authorized':False,'tokenization_authorized':False,'corpus_revised':False,'protocol_refrozen':False,'human_experiment_authorization_issued':False,
            'next_gate':'Human representation resolution, versioned private corpus update or documented retention/defer, new protocol/seal, and fresh explicit experiment authorization required before any execution.'}

def main(argv=None):
    p=argparse.ArgumentParser(description='Validate review decisions without inspecting issue text, modifying files, or authorizing execution')
    p.add_argument('--phase8a',type=Path,required=True,help='Path to existing local phase8a-20261031 folder')
    p.add_argument('--review',type=Path,default=Path(__file__).resolve().with_name('REPRESENTATION_REVIEW.csv'))
    args=p.parse_args(argv)
    src=args.phase8a/'REPRESENTATION_IMPACT_AUDIT.csv'
    try:
        b=src.read_bytes()
        if hashlib.sha256(b).hexdigest()!=EXPECTED_AUDIT_SHA256: raise ValueError('Frozen Phase 8A representation impact audit SHA256 mismatch; stop')
        _,audit=load_csv(src)
        fields,review=load_csv(args.review)
        if fields!=FIELDNAMES: raise ValueError('Review CSV header differs from frozen template')
        result=validate(review,audit)
    except (ValueError,OSError,csv.Error) as e:
        result={'status':'INVALID_REVIEW_PACKAGE','validation_errors':[str(e)],'execution_authorized':False,'tokenization_authorized':False}
    print(json.dumps(result,ensure_ascii=False,indent=2))
    return {'REVIEW_RECORDED_ONLY':0,'REVIEW_INCOMPLETE':2,'INVALID_REVIEW_PACKAGE':3}[result['status']]

if __name__=='__main__': sys.exit(main())
