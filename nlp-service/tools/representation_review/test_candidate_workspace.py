"""Safety and binding checks for local-only synthetic candidate drafts."""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from candidate_workspace import init_workspace, check_workspace, digest


class CandidateWorkspaceTests(unittest.TestCase):
    def setUp(self):
        self.workdir = tempfile.TemporaryDirectory()
        self.addCleanup(self.workdir.cleanup)
        self.base = Path(self.workdir.name)
        self.phase8a = self.base / 'phase8a'
        self.phase7d = self.base / 'phase7d'
        self.input_dir = self.base / 'private'
        for path in (self.phase8a, self.phase7d, self.input_dir):
            path.mkdir(mode=0o700)
        self.review = self.base / 'review.csv'
        self.review.write_text('a,b\n')
        self.dest = self.base / 'new-private-staging'
        self.candidates = []
        self.inputs = []
        for i in range(6):
            ident = f'github/demo/demo/{i}'
            source = self.input_dir / f'issue-{i}.json'
            data = json.dumps({
                'issue_id':ident,
                'title':f'Demo bug {i}',
                'description':f'Synthetic minimized symptom {i}',
                'inference_authorized': False,
                'public_redistribution':'NOT_CLEARED',
                'representation':'MINIMIZED_TITLE_NEWLINE_DESCRIPTION',
            }).encode()
            source.write_bytes(data)
            source.chmod(0o600)
            sha = digest(data)
            self.inputs.append({'issue_id':ident,'snapshot_path':str(source),'snapshot_sha256':sha})
            self.candidates.append({'issue_id':ident,'source_snapshot_sha256':sha,
                                    'review_proposal_sha256':digest(f'proposal {i}'.encode()),
                                    'affected_pairs':[f'P7B-{i:03d}']})
        (self.phase8a/'input_manifest.json').write_text(json.dumps({'inputs':self.inputs}))
        (self.phase7d/'PRIVATE_CORPUS_MANIFEST.json').write_text('{}')
        self.plan = {'status':'REVISION_PREPARATION_ONLY',
                     'revision_candidates':self.candidates,
                     'frozen_corpus_version':'synthetic-v1'}
        self.patch = patch('candidate_workspace.prepare_plan',return_value=self.plan)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def init(self):
        return init_workspace(self.phase8a,self.phase7d,self.review,self.dest)

    def check(self):
        return check_workspace(self.phase8a,self.phase7d,self.review,self.dest)

    def test_initial_drafts_preserve_source_and_gates(self):
        original={p['issue_id']:Path(p['snapshot_path']).read_bytes() for p in self.inputs}
        result=self.init()
        self.assertEqual(6,result['drafts_created'])
        self.assertFalse(result['execution_authorized'])
        self.assertFalse(result['corpus_revised'])
        self.assertEqual(0o700, self.dest.stat().st_mode & 0o777)
        res=self.check()
        self.assertEqual('DRAFTS_INCOMPLETE',res['status'])
        self.assertEqual(6,res['unchanged_count'])
        for item in self.inputs:
            self.assertEqual(original[item['issue_id']],Path(item['snapshot_path']).read_bytes())
        for draft in (self.dest/'drafts').glob('*.txt'):
            self.assertEqual(0o600,draft.stat().st_mode & 0o777)

    def test_edit_six_drafts_still_not_approval(self):
        self.init()
        for f in (self.dest/'drafts').glob('*.txt'):
            f.write_text(f.read_text()+'\nHuman edited synthetic detail')
        result=self.check()
        self.assertEqual('DRAFTS_EDITED_MANUAL_REVIEW_REQUIRED',result['status'])
        self.assertEqual(6,result['changed_count'])
        self.assertFalse(result['privacy_approved_for_new_text'])
        self.assertFalse(result['execution_authorized'])
        self.assertFalse(result['corpus_revised'])
        self.assertNotIn('Human edited',json.dumps(result))

    def test_cannot_reinitialize_and_overwrite(self):
        self.init()
        with self.assertRaisesRegex(ValueError,'already exists'):
            self.init()

    def test_drafts_inside_git_repo_blocked(self):
        repo=self.base/'repo'
        repo.mkdir()
        (repo/'.git').mkdir()
        self.dest=repo/'drafts'
        with self.assertRaisesRegex(ValueError,'Git worktree'):
            self.init()
        self.assertFalse(self.dest.exists())

    def test_parent_symlink_alias_to_safe_directory_allowed(self):
        # Mirrors macOS /var -> /private/var temporary-dir alias behavior.
        real_parent = self.base / 'real-private-parent'
        real_parent.mkdir(mode=0o700)
        alias = self.base / 'safe-parent-alias'
        alias.symlink_to(real_parent, target_is_directory=True)
        self.dest = alias / 'drafts'
        response = self.init()
        self.assertEqual((real_parent / 'drafts').resolve(), Path(response['output_root']))
        self.assertEqual('DRAFTS_INCOMPLETE', self.check()['status'])

    def test_parent_symlink_alias_into_git_repo_blocked(self):
        repo = self.base / 'git-target'
        repo.mkdir()
        (repo / '.git').mkdir()
        alias = self.base / 'git-parent-alias'
        alias.symlink_to(repo, target_is_directory=True)
        self.dest = alias / 'drafts'
        with self.assertRaisesRegex(ValueError, 'Git worktree'):
            self.init()
        self.assertFalse((repo / 'drafts').exists())

    def test_finder_metadata_is_ignored(self):
        self.init()
        (self.dest / 'drafts' / '.DS_Store').write_bytes(b'synthetic Finder metadata')
        result = self.check()
        self.assertEqual('DRAFTS_INCOMPLETE', result['status'])
        self.assertEqual(6, result['unchanged_count'])

    def test_unknown_extra_draft_is_blocked(self):
        self.init()
        (self.dest / 'drafts' / 'unexpected-copy.txt').write_text('synthetic data')
        with self.assertRaisesRegex(ValueError, 'unexpected files'):
            self.check()

    def test_review_change_blocked(self):
        self.init()
        self.review.write_text('changed-review')
        with self.assertRaisesRegex(ValueError,'review binding'):
            self.check()

    def test_symlink_draft_blocked(self):
        self.init()
        first=next((self.dest/'drafts').glob('*.txt'))
        target=self.base/'other.txt'
        target.write_text('trap')
        first.unlink()
        first.symlink_to(target)
        with self.assertRaisesRegex(ValueError,'missing or symlinked'):
            self.check()

    def test_world_readable_draft_blocked(self):
        self.init()
        first=next((self.dest/'drafts').glob('*.txt'))
        first.chmod(0o644)
        with self.assertRaisesRegex(ValueError,'excessive permissions'):
            self.check()

    def test_snapshot_integrity_blocked(self):
        self.inputs[0]['snapshot_sha256']='f'*64
        (self.phase8a/'input_manifest.json').write_text(json.dumps({'inputs':self.inputs}))
        with self.assertRaisesRegex(ValueError,'digest mismatch'):
            self.init()
        self.assertFalse(self.dest.exists())

    def test_malformed_staging_authorization_blocked(self):
        self.init()
        staging=self.dest/'STAGING_MANIFEST.json'
        data=json.loads(staging.read_text())
        data['execution_authorized']=True
        staging.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError,'unexpected authorization'):
            self.check()


if __name__=='__main__':
    unittest.main()
