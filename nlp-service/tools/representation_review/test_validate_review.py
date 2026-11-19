import unittest
from validate_review import validate, expected

class ReviewValidationTests(unittest.TestCase):
    def setUp(self):
        self.audit=[]
        for i in range(13):
            self.audit.append({'issue_id':f'issue-{i}', 'code_log_environment_removed':'True',
              'severity':'REVISION_RECOMMENDED_BEFORE_INFERENCE' if i<5 else 'MATERIAL_CONTEXT_LOSS_ACKNOWLEDGEMENT_REQUIRED',
              'affected_pair_ids': ';'.join(['P'+str(i), 'P'+str((i+1)%21)]) if i<8 else 'P'+str(i+7),
              'redaction_version':'v2','snapshot_sha256':f'hash-{i}'})
        # Produce exactly 21 unique pair tokens and 11 for high-priority 5 issues.
        for i in range(5):
            self.audit[i]['affected_pair_ids']=';'.join(['P'+str(j) for j in (range(i*2, i*2+3) if i==4 else range(i*2,i*2+2))])
        for i in range(5,13):
            self.audit[i]['affected_pair_ids']=';'.join(['P'+str(i+6)] if i<12 else ['P20'])
        # Include all 21 unique: high 0..10, remaining 11..20.
        self.audit[5]['affected_pair_ids']='P11;P12;P13'
        self.audit[6]['affected_pair_ids']='P14;P15;P16'
        self.audit[7]['affected_pair_ids']='P17;P18;P19'
        self.audit[8]['affected_pair_ids']='P20'
        self.audit[9]['affected_pair_ids']='P20'
        self.audit[10]['affected_pair_ids']='P19'
        self.audit[11]['affected_pair_ids']='P18'
        self.audit[12]['affected_pair_ids']='P17'
        expected(self.audit)
        self.review=[]
        for i,a in enumerate(self.audit):
            self.review.append({'issue_id':a['issue_id'],'priority':'HIGH' if i<5 else 'MODERATE',
              'redaction_version':'v2','affected_pair_ids':a['affected_pair_ids'],
              'source_snapshot_sha256':a['snapshot_sha256'],'decision':'PENDING',
              'proposed_minimal_change':'','evidence_reference':'','rights_privacy_reviewed':'',
              'reviewer_name':'','reviewed_at':'','reason':''})

    def test_blank_is_pending(self):
        result=validate(self.review,self.audit)
        self.assertEqual('REVIEW_INCOMPLETE',result['status'])
        self.assertFalse(result['execution_authorized'])

    def test_tampered_source_binding_rejected(self):
        self.review[0]['source_snapshot_sha256']='wrong'
        result=validate(self.review,self.audit)
        self.assertEqual('INVALID_REVIEW_PACKAGE',result['status'])

    def test_incomplete_human_decision_rejected(self):
        self.review[0]['decision']='REQUEST_REVISION'
        result=validate(self.review,self.audit)
        self.assertEqual('INVALID_REVIEW_PACKAGE',result['status'])

    def test_full_synthetic_review_never_authorizes(self):
        for r in self.review:
            r.update(decision='RETAIN_WITH_RISK',evidence_reference='synthetic-fixture',rights_privacy_reviewed='YES',reviewer_name='SYNTHETIC TEST ONLY',reviewed_at='2026-10-31T12:00:00+07:00',reason='Synthetic fixture; not an actual review')
        result=validate(self.review,self.audit)
        self.assertEqual('REVIEW_RECORDED_ONLY',result['status'])
        self.assertFalse(result['execution_authorized'])
        self.assertFalse(result['tokenization_authorized'])

if __name__=='__main__': unittest.main()
